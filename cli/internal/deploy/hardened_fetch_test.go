package deploy

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

type tarEntrySpec struct {
	Name     string
	Typeflag byte
	Mode     int64
	Body     []byte
	Linkname string
}

func buildTestTarGz(t *testing.T, entries []tarEntrySpec) []byte {
	t.Helper()

	var buf bytes.Buffer
	gzw := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gzw)

	for _, e := range entries {
		typeflag := e.Typeflag
		if typeflag == 0 {
			typeflag = tar.TypeReg
		}
		mode := e.Mode
		if mode == 0 {
			if typeflag == tar.TypeDir {
				mode = 0o755
			} else {
				mode = 0o644
			}
		}

		hdr := &tar.Header{
			Name:     e.Name,
			Typeflag: typeflag,
			Mode:     mode,
			Size:     int64(len(e.Body)),
			Linkname: e.Linkname,
		}
		if typeflag == tar.TypeDir || typeflag == tar.TypeSymlink || typeflag == tar.TypeLink {
			hdr.Size = 0
		}

		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatalf("write tar header %q: %v", e.Name, err)
		}
		if typeflag == tar.TypeReg && len(e.Body) > 0 {
			if _, err := tw.Write(e.Body); err != nil {
				t.Fatalf("write tar body %q: %v", e.Name, err)
			}
		}
	}

	if err := tw.Close(); err != nil {
		t.Fatalf("close tar writer: %v", err)
	}
	if err := gzw.Close(); err != nil {
		t.Fatalf("close gzip writer: %v", err)
	}

	return buf.Bytes()
}

func TestCredentialsRedaction(t *testing.T) {
	secret := "ghs_super_secret_token_987654321"
	creds := FetchCredentials{
		AuthHeader:    "Bearer " + secret,
		HTTPSUsername: "x-access-token",
		HTTPSToken:    secret,
	}

	formattedV := fmt.Sprintf("%v", creds)
	formattedPlusV := fmt.Sprintf("%+v", creds)
	formattedHashV := fmt.Sprintf("%#v", creds)

	for name, out := range map[string]string{
		"%v":  formattedV,
		"%+v": formattedPlusV,
		"%#v": formattedHashV,
	} {
		if strings.Contains(out, secret) {
			t.Fatalf("format %s leaked secret token: %q", name, out)
		}
		if out != "[REDACTED]" {
			t.Fatalf("format %s expected [REDACTED], got %q", name, out)
		}
	}

	payload := FetchPayload{
		SchemaVersion: 1,
		Mode:          "tarball",
		CommitSHA:     "deadbeef",
		TarballURL:    "https://api.github.com/repositories/1/tarball/deadbeef",
		Credentials:   creds,
	}
	if strings.Contains(fmt.Sprintf("%+v", payload), secret) {
		t.Fatalf("FetchPayload String() leaked secret token")
	}
}

func TestUnsupportedSchemaVersionFailsFast(t *testing.T) {
	raw := `{"schema_version":99,"mode":"tarball","commit_sha":"abc123","tarball_url":"https://api.github.com/repositories/1/tarball/abc123","auth_header":"Bearer tok"}`

	_, err := ReadFetchPayload(strings.NewReader(raw), time.Second)
	if err == nil {
		t.Fatal("expected error for unsupported schema_version, got nil")
	}
	if !strings.Contains(err.Error(), "schema_version 99") {
		t.Fatalf("expected clear version-mismatch error, got: %v", err)
	}
}

func TestReadFetchPayloadTimesOutOnStalledStdin(t *testing.T) {
	pr, pw := io.Pipe()
	defer pw.Close()
	defer pr.Close()

	start := time.Now()
	_, err := ReadFetchPayload(pr, 80*time.Millisecond)
	if err == nil {
		t.Fatal("expected timeout error on stalled stdin, got nil")
	}
	if !strings.Contains(err.Error(), "timed out") {
		t.Fatalf("expected timeout error message, got: %v", err)
	}
	if time.Since(start) > 2*time.Second {
		t.Fatalf("timeout took unexpectedly long: %v", time.Since(start))
	}
}

func TestTarballRejectsPathTraversal(t *testing.T) {
	cases := []struct {
		name      string
		entryName string
	}{
		{name: "relative_parent_traversal", entryName: "repo-abc/../../etc/passwd"},
		{name: "bare_parent_traversal", entryName: "../../etc/passwd"},
		{name: "absolute_unix_path", entryName: "/etc/passwd"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			archive := buildTestTarGz(t, []tarEntrySpec{
				{Name: tc.entryName, Body: []byte("root:x:0:0:")},
			})

			destDir := t.TempDir()
			err := ExtractTarGzSafely(bytes.NewReader(archive), destDir, TarExtractOptions{
				StripComponents: 1,
			})
			if err == nil {
				t.Fatalf("expected error rejecting %q, got nil", tc.entryName)
			}
		})
	}
}

func TestTarballRejectsSymlinkEscape(t *testing.T) {
	cases := []struct {
		name     string
		linkname string
	}{
		{name: "relative_escape", linkname: "../../../etc/passwd"},
		{name: "absolute_target", linkname: "/etc/passwd"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			archive := buildTestTarGz(t, []tarEntrySpec{
				{Name: "repo-abc/public/escape", Typeflag: tar.TypeSymlink, Linkname: tc.linkname},
			})

			destDir := t.TempDir()
			err := ExtractTarGzSafely(bytes.NewReader(archive), destDir, TarExtractOptions{
				StripComponents: 1,
			})
			if err == nil {
				t.Fatalf("expected error rejecting symlink target %q, got nil", tc.linkname)
			}
		})
	}
}

func TestTarballStripsSetuidAndCapsDecompressedSize(t *testing.T) {
	// 1. Verify setuid/setgid/sticky bits (0o4755 -> 0o755) are stripped.
	if got := stripSpecialModeBits(0o4755); got != 0o755 {
		t.Fatalf("expected stripSpecialModeBits(04755) == 0755, got %04o", got)
	}
	if got := stripSpecialModeBits(0o6755); got != 0o755 {
		t.Fatalf("expected stripSpecialModeBits(06755) == 0755, got %04o", got)
	}
	if got := stripSpecialModeBits(0o1777); got != 0o777 {
		t.Fatalf("expected stripSpecialModeBits(01777) == 0777, got %04o", got)
	}

	archive := buildTestTarGz(t, []tarEntrySpec{
		{Name: "repo-abc/bin/tool.sh", Mode: 0o4755, Body: []byte("#!/bin/sh\necho hi\n")},
	})

	destDir := t.TempDir()
	if err := ExtractTarGzSafely(bytes.NewReader(archive), destDir, TarExtractOptions{
		StripComponents: 1,
	}); err != nil {
		t.Fatalf("ExtractTarGzSafely failed: %v", err)
	}

	extractedPath := filepath.Join(destDir, "bin", "tool.sh")
	info, err := os.Stat(extractedPath)
	if err != nil {
		t.Fatalf("stat extracted file: %v", err)
	}
	if info.Mode()&(os.ModeSetuid|os.ModeSetgid|os.ModeSticky) != 0 {
		t.Fatalf("special mode bits were not stripped: %v", info.Mode())
	}
	if runtime.GOOS != "windows" && info.Mode().Perm() != 0o755 {
		t.Fatalf("expected perm 0755 on unix, got %04o", info.Mode().Perm())
	}

	// 2. Verify decompression bomb cap aborts extraction.
	bombPayload := bytes.Repeat([]byte("A"), 128*1024) // 128 KiB highly compressible
	bombArchive := buildTestTarGz(t, []tarEntrySpec{
		{Name: "repo-abc/bomb.txt", Body: bombPayload},
	})

	bombDest := t.TempDir()
	err = ExtractTarGzSafely(bytes.NewReader(bombArchive), bombDest, TarExtractOptions{
		StripComponents:      1,
		MaxDecompressedBytes: 16 * 1024, // 16 KiB cap
	})
	if err == nil {
		t.Fatal("expected decompression bomb error, got nil")
	}
	if !strings.Contains(err.Error(), "decompressed size exceeds") {
		t.Fatalf("expected decompressed size cap error, got: %v", err)
	}
}

func TestTarballDetectsSubmodulesAndLFSWarning(t *testing.T) {
	archive := buildTestTarGz(t, []tarEntrySpec{
		{Name: "repo-abc/index.php", Body: []byte("<?php echo 'ok';")},
		{Name: "repo-abc/.gitmodules", Body: []byte("[submodule \"lib\"]\n\tpath = lib\n\turl = https://github.com/acme/lib.git\n")},
		{Name: "repo-abc/.gitattributes", Body: []byte("*.psd filter=lfs diff=lfs merge=lfs -text\n")},
	})

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(archive)
	}))
	defer server.Close()

	releaseDir := filepath.Join(t.TempDir(), "releases", "20261125_01")
	var logBuf bytes.Buffer

	warnings, err := DownloadAndExtractTarball(context.Background(), TarballFetchOptions{
		URL:         server.URL + "/tarball/deadbeef",
		Credentials: FetchCredentials{AuthHeader: "Bearer ghs_test"},
		ReleaseDir:  releaseDir,
	}, &logBuf)
	if err != nil {
		t.Fatalf("DownloadAndExtractTarball failed: %v", err)
	}

	if len(warnings) != 2 {
		t.Fatalf("expected 2 warnings (.gitmodules + filter=lfs), got %d: %v", len(warnings), warnings)
	}

	logOutput := logBuf.String()
	if !strings.Contains(logOutput, ".gitmodules") || !strings.Contains(logOutput, "Shallow Clone") {
		t.Fatalf("expected .gitmodules / Shallow Clone warning in log output, got:\n%s", logOutput)
	}
	if !strings.Contains(logOutput, "filter=lfs") {
		t.Fatalf("expected filter=lfs warning in log output, got:\n%s", logOutput)
	}
}

func TestTarballFailureCleansPartialReleaseAndPreservesCurrentSymlink(t *testing.T) {
	domainRoot := t.TempDir()
	prevRelease := filepath.Join(domainRoot, "releases", "20261124_prev")
	if err := os.MkdirAll(prevRelease, 0o755); err != nil {
		t.Fatalf("mkdir prev release: %v", err)
	}

	currentLink := filepath.Join(domainRoot, "current")
	// Use a sentinel file or symlink to verify current is untouched on failure.
	symlinkCreated := os.Symlink(prevRelease, currentLink) == nil

	// Malicious archive: first entry writes a file (creating partial state), second entry escapes.
	badArchive := buildTestTarGz(t, []tarEntrySpec{
		{Name: "repo-abc/good.txt", Body: []byte("partial file")},
		{Name: "repo-abc/../../evil.txt", Body: []byte("escape")},
	})

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(badArchive)
	}))
	defer server.Close()

	failedReleaseDir := filepath.Join(domainRoot, "releases", "20261125_bad")
	_, err := DownloadAndExtractTarball(context.Background(), TarballFetchOptions{
		URL:         server.URL + "/tarball/bad",
		Credentials: FetchCredentials{AuthHeader: "Bearer ghs_test"},
		ReleaseDir:  failedReleaseDir,
	}, io.Discard)
	if err == nil {
		t.Fatal("expected error from malicious tarball, got nil")
	}

	if _, statErr := os.Stat(failedReleaseDir); !os.IsNotExist(statErr) {
		t.Fatalf("expected partial release directory %s to be deleted, stat err: %v", failedReleaseDir, statErr)
	}

	if symlinkCreated {
		target, readErr := os.Readlink(currentLink)
		if readErr != nil {
			t.Fatalf("read current symlink: %v", readErr)
		}
		if target != prevRelease {
			t.Fatalf("expected current symlink to remain %q, got %q", prevRelease, target)
		}
	}
}

func TestTarballRedirectStripsAuthorizationOnCrossHost(t *testing.T) {
	var codeloadAuthHeader string
	archive := buildTestTarGz(t, []tarEntrySpec{
		{Name: "repo-abc/ok.txt", Body: []byte("ok")},
	})

	// Secondary host simulates codeload.github.com (different host/port than apiServer).
	codeloadServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		codeloadAuthHeader = r.Header.Get("Authorization")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(archive)
	}))
	defer codeloadServer.Close()

	var apiAuthHeader string
	apiServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		apiAuthHeader = r.Header.Get("Authorization")
		http.Redirect(w, r, codeloadServer.URL+"/signed-archive", http.StatusFound)
	}))
	defer apiServer.Close()

	releaseDir := filepath.Join(t.TempDir(), "releases", "20261125_redirect")
	_, err := DownloadAndExtractTarball(context.Background(), TarballFetchOptions{
		URL:         apiServer.URL + "/repositories/123/tarball/abc",
		Credentials: FetchCredentials{AuthHeader: "Bearer ghs_secret_api_token"},
		ReleaseDir:  releaseDir,
	}, io.Discard)
	if err != nil {
		t.Fatalf("DownloadAndExtractTarball failed: %v", err)
	}

	if apiAuthHeader != "Bearer ghs_secret_api_token" {
		t.Fatalf("expected Authorization header on initial API request, got %q", apiAuthHeader)
	}
	if codeloadAuthHeader != "" {
		t.Fatalf("expected Authorization header to be stripped on cross-host redirect, got %q", codeloadAuthHeader)
	}
}

func TestAskpassHandlesUsernameAndPasswordAndStartupSweepRemovesStaleDirs(t *testing.T) {
	tempBase := t.TempDir()

	// 1. Startup sweep removes stale lc-askpass-* dirs older than 1 hour and keeps fresh ones.
	staleDir := filepath.Join(tempBase, "lc-askpass-stale-001")
	freshDir := filepath.Join(tempBase, "lc-askpass-fresh-002")
	unrelatedDir := filepath.Join(tempBase, "other-dir-003")

	for _, d := range []string{staleDir, freshDir, unrelatedDir} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			t.Fatalf("mkdir %s: %v", d, err)
		}
	}

	twoHoursAgo := time.Now().Add(-2 * time.Hour)
	if err := os.Chtimes(staleDir, twoHoursAgo, twoHoursAgo); err != nil {
		t.Fatalf("chtimes stale dir: %v", err)
	}
	if err := os.Chtimes(unrelatedDir, twoHoursAgo, twoHoursAgo); err != nil {
		t.Fatalf("chtimes unrelated dir: %v", err)
	}

	if err := SweepStaleAskpassDirs(tempBase, time.Hour); err != nil {
		t.Fatalf("SweepStaleAskpassDirs failed: %v", err)
	}

	if _, err := os.Stat(staleDir); !os.IsNotExist(err) {
		t.Fatalf("expected stale askpass dir to be swept, stat err: %v", err)
	}
	if _, err := os.Stat(freshDir); err != nil {
		t.Fatalf("expected fresh askpass dir to remain, stat err: %v", err)
	}
	if _, err := os.Stat(unrelatedDir); err != nil {
		t.Fatalf("expected unrelated dir to remain, stat err: %v", err)
	}

	// 2. Askpass helper creates 0700 dir/script, handles Username and Password prompts, and cleans up.
	creds := FetchCredentials{
		HTTPSUsername: "x-access-token",
		HTTPSToken:    "ghs_askpass_secret_tok$!@#",
	}

	dirPath, scriptPath, cleanup, err := CreateAskpassHelper(tempBase, creds, 0, 0)
	if err != nil {
		t.Fatalf("CreateAskpassHelper failed: %v", err)
	}

	if runtime.GOOS != "windows" {
		dirInfo, err := os.Stat(dirPath)
		if err != nil {
			t.Fatalf("stat askpass dir: %v", err)
		}
		if dirInfo.Mode().Perm() != 0o700 {
			t.Fatalf("expected askpass dir perm 0700, got %04o", dirInfo.Mode().Perm())
		}
	}

	scriptRaw, err := os.ReadFile(scriptPath)
	if err != nil {
		t.Fatalf("read askpass script: %v", err)
	}
	scriptContent := string(scriptRaw)
	if !strings.Contains(scriptContent, "Username") && !strings.Contains(scriptContent, "[Uu]sername") {
		t.Fatalf("askpass script missing username matcher: %s", scriptContent)
	}
	if !strings.Contains(scriptContent, "[Pp]assword") {
		t.Fatalf("askpass script missing password matcher: %s", scriptContent)
	}

	// Execute the generated askpass script with sh if available on the host (Linux/macOS/Git Bash).
	if shPath, lookErr := exec.LookPath("sh"); lookErr == nil {
		outUser, err := exec.Command(shPath, scriptPath, "Username for 'https://github.com':").Output()
		if err != nil {
			t.Fatalf("execute askpass for Username: %v", err)
		}
		if strings.TrimSpace(string(outUser)) != creds.HTTPSUsername {
			t.Fatalf("askpass Username output = %q, want %q", strings.TrimSpace(string(outUser)), creds.HTTPSUsername)
		}

		outPass, err := exec.Command(shPath, scriptPath, "Password for 'https://x-access-token@github.com':").Output()
		if err != nil {
			t.Fatalf("execute askpass for Password: %v", err)
		}
		if strings.TrimSpace(string(outPass)) != creds.HTTPSToken {
			t.Fatalf("askpass Password output = %q, want %q", strings.TrimSpace(string(outPass)), creds.HTTPSToken)
		}
	}

	cleanup()
	if _, err := os.Stat(dirPath); !os.IsNotExist(err) {
		t.Fatalf("expected cleanup to remove askpass dir %s, stat err: %v", dirPath, err)
	}
}
