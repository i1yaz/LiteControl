package deploy

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const (
	// DefaultTarballDownloadTimeout caps the total tarball download duration.
	DefaultTarballDownloadTimeout = 120 * time.Second

	// DefaultMaxCompressedBytes caps the compressed tarball download size (500 MiB).
	DefaultMaxCompressedBytes int64 = 500 * 1024 * 1024

	// DefaultMaxDecompressedBytes caps cumulative decompressed output (2 GiB) to stop gzip bombs.
	DefaultMaxDecompressedBytes int64 = 2 * 1024 * 1024 * 1024

	// DefaultMaxExtractedFiles caps the total number of archive entries extracted.
	DefaultMaxExtractedFiles = 100_000
)

// TarExtractOptions configures hardened tar.gz extraction limits.
type TarExtractOptions struct {
	StripComponents      int
	MaxDecompressedBytes int64
	MaxFiles             int
}

// TarballFetchOptions configures hardened HTTP tarball download and extraction.
type TarballFetchOptions struct {
	URL                  string
	Credentials          FetchCredentials
	ReleaseDir           string
	LinuxUser            string
	DownloadTimeout      time.Duration
	MaxCompressedBytes   int64
	MaxDecompressedBytes int64
	MaxFiles             int
	StripComponents      int
	HTTPClient           *http.Client
}

// NewHardenedTarballHTTPClient builds an HTTP client that strips the Authorization header
// whenever a redirect crosses to a different host (e.g., api.github.com -> codeload.github.com).
func NewHardenedTarballHTTPClient(timeout time.Duration) *http.Client {
	if timeout <= 0 {
		timeout = DefaultTarballDownloadTimeout
	}

	return &http.Client{
		Timeout: timeout,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 10 {
				return errors.New("stopped after 10 redirects")
			}
			if len(via) > 0 && !strings.EqualFold(req.URL.Host, via[0].URL.Host) {
				req.Header.Del("Authorization")
			}
			return nil
		},
	}
}

// DownloadAndExtractTarball downloads a provider tarball with strict redirect, size, and
// timeout guards, extracts it safely (dropping privileges to the tenant OS user on Linux
// when running as root), inspects for submodules/LFS warnings, and removes any partial
// releaseDir on failure without touching the current symlink.
func DownloadAndExtractTarball(ctx context.Context, opts TarballFetchOptions, logWriter io.Writer) ([]string, error) {
	if strings.TrimSpace(opts.URL) == "" {
		return nil, errors.New("tarball URL is required")
	}
	if strings.TrimSpace(opts.ReleaseDir) == "" {
		return nil, errors.New("release directory is required")
	}

	timeout := opts.DownloadTimeout
	if timeout <= 0 {
		timeout = DefaultTarballDownloadTimeout
	}
	maxCompressed := opts.MaxCompressedBytes
	if maxCompressed <= 0 {
		maxCompressed = DefaultMaxCompressedBytes
	}
	maxDecompressed := opts.MaxDecompressedBytes
	if maxDecompressed <= 0 {
		maxDecompressed = DefaultMaxDecompressedBytes
	}
	maxFiles := opts.MaxFiles
	if maxFiles <= 0 {
		maxFiles = DefaultMaxExtractedFiles
	}
	stripComponents := opts.StripComponents
	if stripComponents <= 0 {
		stripComponents = 1
	}

	client := opts.HTTPClient
	if client == nil {
		client = NewHardenedTarballHTTPClient(timeout)
	}

	// Clean up partial release directory on any error; never touch current symlink.
	success := false
	defer func() {
		if !success {
			_ = os.RemoveAll(opts.ReleaseDir)
		}
	}()

	reqCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	req, err := http.NewRequestWithContext(reqCtx, http.MethodGet, opts.URL, nil)
	if err != nil {
		return nil, fmt.Errorf("create tarball request: %w", err)
	}
	req.Header.Set("User-Agent", "LiteControl-Deploy")
	req.Header.Set("Accept", "application/vnd.github+json")
	if strings.TrimSpace(opts.Credentials.AuthHeader) != "" {
		req.Header.Set("Authorization", opts.Credentials.AuthHeader)
	}

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("download tarball: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("tarball download failed with HTTP %d", resp.StatusCode)
	}

	if resp.ContentLength > maxCompressed {
		return nil, fmt.Errorf("tarball compressed size %d exceeds maximum allowed %d bytes", resp.ContentLength, maxCompressed)
	}

	// Stream response to a bounded temporary file so we can enforce maxCompressed strictly
	// and optionally pass it to an unprivileged extraction child process.
	tmpFile, err := os.CreateTemp("", "lc-tarball-*.tar.gz")
	if err != nil {
		return nil, fmt.Errorf("create temp tarball file: %w", err)
	}
	tmpPath := tmpFile.Name()
	defer func() {
		_ = tmpFile.Close()
		_ = os.Remove(tmpPath)
	}()

	written, err := io.Copy(tmpFile, io.LimitReader(resp.Body, maxCompressed+1))
	if err != nil {
		return nil, fmt.Errorf("stream tarball download: %w", err)
	}
	if written > maxCompressed {
		return nil, fmt.Errorf("tarball compressed download exceeds maximum allowed %d bytes", maxCompressed)
	}
	if _, err := tmpFile.Seek(0, io.SeekStart); err != nil {
		return nil, fmt.Errorf("rewind temp tarball: %w", err)
	}

	extractOpts := TarExtractOptions{
		StripComponents:      stripComponents,
		MaxDecompressedBytes: maxDecompressed,
		MaxFiles:             maxFiles,
	}

	// On Linux when running as root, drop privileges strictly to the tenant OS user for extraction.
	if runtime.GOOS == "linux" && os.Geteuid() == 0 && strings.TrimSpace(opts.LinuxUser) != "" {
		uid, gid, err := lookupUIDGID(opts.LinuxUser)
		if err != nil {
			return nil, fmt.Errorf("lookup tenant user for tarball extraction: %w", err)
		}
		if err := mkdirAllOwned(opts.ReleaseDir, 0o750, uid, gid); err != nil {
			return nil, fmt.Errorf("prepare tenant release dir: %w", err)
		}
		if err := os.Chmod(tmpPath, 0o640); err != nil {
			return nil, fmt.Errorf("chmod temp tarball: %w", err)
		}
		if err := os.Chown(tmpPath, uid, gid); err != nil {
			return nil, fmt.Errorf("chown temp tarball: %w", err)
		}
		if err := runUnprivilegedTarballExtract(reqCtx, opts.LinuxUser, tmpPath, opts.ReleaseDir, extractOpts, logWriter); err != nil {
			return nil, err
		}
	} else {
		if err := os.MkdirAll(opts.ReleaseDir, 0o755); err != nil {
			return nil, fmt.Errorf("create release dir: %w", err)
		}
		if err := ExtractTarGzSafely(tmpFile, opts.ReleaseDir, extractOpts); err != nil {
			return nil, err
		}
	}

	if err := removeAndVerifyNoGit(opts.ReleaseDir); err != nil {
		return nil, err
	}

	warnings := DetectSubmodulesOrLFS(opts.ReleaseDir)
	for _, warning := range warnings {
		logStep(logWriter, "WARNING: "+warning)
	}

	success = true
	return warnings, nil
}

// runUnprivilegedTarballExtract re-invokes the current lc-cli binary with
// `deploy extract-tarball` strictly under the tenant's UID/GID.
func runUnprivilegedTarballExtract(
	ctx context.Context,
	linuxUser string,
	archivePath string,
	releaseDir string,
	opts TarExtractOptions,
	logWriter io.Writer,
) error {
	selfBin, err := os.Executable()
	if err != nil {
		return fmt.Errorf("resolve lc-cli executable path: %w", err)
	}

	cmdStr := fmt.Sprintf(
		"%s deploy extract-tarball --archive %s --dest %s --strip-components %d --max-bytes %d --max-files %d",
		shellQuote(selfBin),
		shellQuote(archivePath),
		shellQuote(releaseDir),
		opts.StripComponents,
		opts.MaxDecompressedBytes,
		opts.MaxFiles,
	)

	if err := RunAsTenantUser(ctx, linuxUser, releaseDir, nil, cmdStr, logWriter); err != nil {
		return fmt.Errorf("unprivileged tarball extraction failed: %w", err)
	}

	return nil
}

// ExtractTarGzSafely extracts a gzipped tar stream into destDir while enforcing:
//   - No path traversal (".."), absolute paths, device nodes, or FIFOs
//   - Symlinks and hardlinks strictly resolve inside destDir
//   - setuid, setgid, and sticky bits stripped from all extracted permissions
//   - Cumulative decompressed byte cap and file-count cap against gzip bombs
func ExtractTarGzSafely(r io.Reader, destDir string, opts TarExtractOptions) error {
	maxBytes := opts.MaxDecompressedBytes
	if maxBytes <= 0 {
		maxBytes = DefaultMaxDecompressedBytes
	}
	maxFiles := opts.MaxFiles
	if maxFiles <= 0 {
		maxFiles = DefaultMaxExtractedFiles
	}

	cleanDest, err := filepath.Abs(destDir)
	if err != nil {
		return fmt.Errorf("resolve dest dir: %w", err)
	}
	if err := os.MkdirAll(cleanDest, 0o755); err != nil {
		return fmt.Errorf("create dest dir: %w", err)
	}

	gzr, err := gzip.NewReader(r)
	if err != nil {
		return fmt.Errorf("open gzip stream: %w", err)
	}
	defer gzr.Close()

	tr := tar.NewReader(gzr)
	var totalBytes int64
	var fileCount int

	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("read tar header: %w", err)
		}

		// Ignore PAX extended metadata headers.
		if hdr.Typeflag == tar.TypeXHeader || hdr.Typeflag == tar.TypeXGlobalHeader || hdr.Typeflag == tar.TypeGNULongName || hdr.Typeflag == tar.TypeGNULongLink {
			continue
		}

		fileCount++
		if fileCount > maxFiles {
			return fmt.Errorf("tarball exceeds maximum allowed file count (%d)", maxFiles)
		}

		// Reject dangerous special file types immediately.
		switch hdr.Typeflag {
		case tar.TypeChar, tar.TypeBlock, tar.TypeFifo:
			return fmt.Errorf("tarball entry %q has forbidden special file type %q", hdr.Name, hdr.Typeflag)
		}

		relPath, skip, err := sanitizeTarEntryPath(hdr.Name, opts.StripComponents)
		if err != nil {
			return err
		}
		if skip {
			continue
		}

		targetPath := filepath.Join(cleanDest, filepath.FromSlash(relPath))
		if !isPathWithinBase(cleanDest, targetPath) {
			return fmt.Errorf("tarball entry %q escapes destination directory", hdr.Name)
		}

		safePerm := stripSpecialModeBits(hdr.Mode)

		switch hdr.Typeflag {
		case tar.TypeDir:
			if safePerm == 0 {
				safePerm = 0o755
			}
			if err := os.MkdirAll(targetPath, safePerm|0o700); err != nil {
				return fmt.Errorf("create directory %s: %w", relPath, err)
			}
			_ = os.Chmod(targetPath, safePerm)

		case tar.TypeReg, tar.TypeRegA:
			if hdr.Size < 0 {
				return fmt.Errorf("tarball entry %q has negative size", hdr.Name)
			}
			if totalBytes+hdr.Size > maxBytes {
				return fmt.Errorf("tarball decompressed size exceeds maximum allowed %d bytes", maxBytes)
			}

			if err := os.MkdirAll(filepath.Dir(targetPath), 0o755); err != nil {
				return fmt.Errorf("create parent dir for %s: %w", relPath, err)
			}

			if safePerm == 0 {
				safePerm = 0o644
			}
			outFile, err := os.OpenFile(targetPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, safePerm)
			if err != nil {
				return fmt.Errorf("create file %s: %w", relPath, err)
			}

			remaining := maxBytes - totalBytes
			n, copyErr := io.Copy(outFile, io.LimitReader(tr, remaining+1))
			closeErr := outFile.Close()
			if copyErr != nil {
				return fmt.Errorf("write file %s: %w", relPath, copyErr)
			}
			if closeErr != nil {
				return fmt.Errorf("close file %s: %w", relPath, closeErr)
			}

			totalBytes += n
			if totalBytes > maxBytes {
				return fmt.Errorf("tarball decompressed size exceeds maximum allowed %d bytes", maxBytes)
			}

			// Re-apply stripped permission bits explicitly (ignores umask and guarantees no setuid/setgid/sticky).
			if err := os.Chmod(targetPath, safePerm); err != nil {
				return fmt.Errorf("chmod file %s: %w", relPath, err)
			}

		case tar.TypeSymlink:
			linkTarget := strings.ReplaceAll(hdr.Linkname, "\\", "/")
			if linkTarget == "" || strings.HasPrefix(linkTarget, "/") || hasWindowsDrivePrefix(linkTarget) {
				return fmt.Errorf("tarball symlink %q points to forbidden absolute target %q", hdr.Name, hdr.Linkname)
			}

			resolvedTarget := filepath.Clean(filepath.Join(filepath.Dir(targetPath), filepath.FromSlash(linkTarget)))
			if !isPathWithinBase(cleanDest, resolvedTarget) {
				return fmt.Errorf("tarball symlink %q -> %q escapes release directory", hdr.Name, hdr.Linkname)
			}

			if err := os.MkdirAll(filepath.Dir(targetPath), 0o755); err != nil {
				return fmt.Errorf("create parent dir for symlink %s: %w", relPath, err)
			}
			_ = os.Remove(targetPath)
			if err := os.Symlink(hdr.Linkname, targetPath); err != nil {
				return fmt.Errorf("create symlink %s: %w", relPath, err)
			}

		case tar.TypeLink:
			linkRel, skipLink, err := sanitizeTarEntryPath(hdr.Linkname, opts.StripComponents)
			if err != nil {
				return fmt.Errorf("tarball hardlink %q has invalid target %q: %w", hdr.Name, hdr.Linkname, err)
			}
			if skipLink {
				return fmt.Errorf("tarball hardlink %q points to stripped root", hdr.Name)
			}
			resolvedLink := filepath.Join(cleanDest, filepath.FromSlash(linkRel))
			if !isPathWithinBase(cleanDest, resolvedLink) {
				return fmt.Errorf("tarball hardlink %q -> %q escapes release directory", hdr.Name, hdr.Linkname)
			}
			if err := os.MkdirAll(filepath.Dir(targetPath), 0o755); err != nil {
				return fmt.Errorf("create parent dir for hardlink %s: %w", relPath, err)
			}
			_ = os.Remove(targetPath)
			if err := os.Link(resolvedLink, targetPath); err != nil {
				return fmt.Errorf("create hardlink %s: %w", relPath, err)
			}

		default:
			return fmt.Errorf("tarball entry %q has unsupported type %q", hdr.Name, hdr.Typeflag)
		}
	}

	return nil
}

// DetectSubmodulesOrLFS inspects an extracted release directory for `.gitmodules`
// or `.gitattributes` containing `filter=lfs` and returns actionable user warnings.
func DetectSubmodulesOrLFS(releaseDir string) []string {
	var warnings []string

	if info, err := os.Stat(filepath.Join(releaseDir, ".gitmodules")); err == nil && !info.IsDir() {
		warnings = append(warnings, "Repository contains .gitmodules (Git submodules are not included in tarballs). Switch fetch driver to Shallow Clone under Advanced if submodules are required.")
	}

	if raw, err := os.ReadFile(filepath.Join(releaseDir, ".gitattributes")); err == nil {
		if strings.Contains(string(raw), "filter=lfs") {
			warnings = append(warnings, "Repository uses Git LFS (filter=lfs in .gitattributes; tarballs only include pointer files). Switch fetch driver to Shallow Clone under Advanced if LFS assets are required.")
		}
	}

	return warnings
}

func sanitizeTarEntryPath(rawName string, stripComponents int) (string, bool, error) {
	if rawName == "" {
		return "", true, nil
	}

	normalized := strings.ReplaceAll(rawName, "\\", "/")
	if strings.HasPrefix(normalized, "/") || hasWindowsDrivePrefix(normalized) {
		return "", false, fmt.Errorf("tarball entry %q uses a forbidden absolute path", rawName)
	}

	parts := strings.Split(normalized, "/")
	cleanParts := make([]string, 0, len(parts))
	for _, part := range parts {
		if part == "" || part == "." {
			continue
		}
		if part == ".." {
			return "", false, fmt.Errorf("tarball entry %q contains forbidden path traversal segment '..'", rawName)
		}
		cleanParts = append(cleanParts, part)
	}

	if len(cleanParts) <= stripComponents {
		return "", true, nil
	}

	stripped := cleanParts[stripComponents:]
	joined := path.Clean(strings.Join(stripped, "/"))
	if joined == "." || joined == "" {
		return "", true, nil
	}
	if strings.HasPrefix(joined, "..") || strings.HasPrefix(joined, "/") {
		return "", false, fmt.Errorf("tarball entry %q escapes destination after stripping components", rawName)
	}

	return joined, false, nil
}

func stripSpecialModeBits(mode int64) os.FileMode {
	// Mask strictly to standard rwxrwxrwx bits (0o777), stripping setuid (0o4000), setgid (0o2000), and sticky (0o1000).
	return os.FileMode(mode) & 0o777
}

func isPathWithinBase(baseDir, candidate string) bool {
	rel, err := filepath.Rel(baseDir, candidate)
	if err != nil {
		return false
	}
	if rel == "." {
		return true
	}
	return rel != ".." && !strings.HasPrefix(rel, ".."+string(os.PathSeparator)) && !filepath.IsAbs(rel)
}

func hasWindowsDrivePrefix(p string) bool {
	return len(p) >= 2 && ((p[0] >= 'a' && p[0] <= 'z') || (p[0] >= 'A' && p[0] <= 'Z')) && p[1] == ':'
}

// keep exec import satisfied on all platforms if needed
var _ = exec.ErrNotFound
