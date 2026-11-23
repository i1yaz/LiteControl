package deploy

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
)

// InitOptions configures `lc-cli deploy init`.
type InitOptions struct {
	User       string
	Domain     string
	WebSubpath string
	SeedShared bool
}

// InitDomain initializes the atomic deployment directory layout, optionally
// seeds shared/ from an existing public_html directory, updates the OLS vhost
// open_basedir & Follow Symbolic Link settings, and reloads OpenLiteSpeed.
func InitDomain(opts InitOptions) error {
	if opts.User == "" {
		return fmt.Errorf("user is required")
	}
	if opts.Domain == "" {
		return fmt.Errorf("domain is required")
	}

	domainRoot := filepath.Join("/home", opts.User, opts.Domain)
	if err := validateHomePath(domainRoot); err != nil {
		return fmt.Errorf("invalid domain root: %w", err)
	}

	uid, gid, err := lookupUserIDs(opts.User)
	if err != nil {
		return err
	}

	dirs := []string{
		filepath.Join(domainRoot, "releases"),
		filepath.Join(domainRoot, "shared"),
		filepath.Join(domainRoot, ".deploy"),
		filepath.Join(domainRoot, ".deploy", "locks"),
		filepath.Join(domainRoot, ".deploy", "logs"),
		filepath.Join(domainRoot, ".deploy", "keys"),
		filepath.Join(domainRoot, ".deploy", "payloads"),
	}
	for _, d := range dirs {
		if err := mkdirAllOwned(d, 0o750, uid, gid); err != nil {
			return err
		}
	}

	if opts.SeedShared {
		if err := seedSharedFromPublicHTML(domainRoot, uid, gid); err != nil {
			return err
		}
	}

	if err := updateOLSVHost(opts.User, opts.Domain, domainRoot); err != nil {
		return err
	}

	return nil
}

func lookupUserIDs(username string) (int, int, error) {
	u, err := user.Lookup(username)
	if err != nil {
		return 0, 0, fmt.Errorf("lookup user %q: %w", username, err)
	}
	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return 0, 0, fmt.Errorf("parse uid %q: %w", u.Uid, err)
	}
	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return 0, 0, fmt.Errorf("parse gid %q: %w", u.Gid, err)
	}
	return uid, gid, nil
}

func mkdirAllOwned(path string, mode os.FileMode, uid, gid int) error {
	if err := os.MkdirAll(path, mode); err != nil {
		return fmt.Errorf("mkdir %s: %w", path, err)
	}
	if runtime.GOOS != "windows" {
		if err := os.Chown(path, uid, gid); err != nil {
			return fmt.Errorf("chown %s: %w", path, err)
		}
	}
	return nil
}

func seedSharedFromPublicHTML(domainRoot string, uid, gid int) error {
	publicHTML := filepath.Join(domainRoot, "public_html")
	info, err := os.Lstat(publicHTML)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("stat %s: %w", publicHTML, err)
	}
	// Only seed when public_html is a real directory (not already a symlink).
	if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
		return nil
	}

	sharedDir := filepath.Join(domainRoot, "shared")

	// 1. Copy .env (0600, user:user)
	srcEnv := filepath.Join(publicHTML, ".env")
	dstEnv := filepath.Join(sharedDir, ".env")
	if fileExists(srcEnv) && !pathExists(dstEnv) {
		if err := copyFileOwned(srcEnv, dstEnv, 0o600, uid, gid); err != nil {
			return err
		}
	}

	// 2. Copy storage recursively
	srcStorage := filepath.Join(publicHTML, "storage")
	dstStorage := filepath.Join(sharedDir, "storage")
	if dirExists(srcStorage) && !pathExists(dstStorage) {
		if err := copyDirPreserve(srcStorage, dstStorage); err != nil {
			return err
		}
	}

	// 3. Copy wp-content/uploads recursively
	srcUploads := filepath.Join(publicHTML, "wp-content", "uploads")
	dstUploads := filepath.Join(sharedDir, "wp-content", "uploads")
	if dirExists(srcUploads) && !pathExists(dstUploads) {
		if err := mkdirAllOwned(filepath.Dir(dstUploads), 0o750, uid, gid); err != nil {
			return err
		}
		if err := copyDirPreserve(srcUploads, dstUploads); err != nil {
			return err
		}
	}

	return nil
}

func copyFileOwned(src, dst string, mode os.FileMode, uid, gid int) error {
	in, err := os.Open(src)
	if err != nil {
		return fmt.Errorf("open %s: %w", src, err)
	}
	defer in.Close()

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return fmt.Errorf("create %s: %w", dst, err)
	}
	if _, err := io.Copy(out, in); err != nil {
		_ = out.Close()
		return fmt.Errorf("copy %s -> %s: %w", src, dst, err)
	}
	if err := out.Close(); err != nil {
		return fmt.Errorf("close %s: %w", dst, err)
	}
	if runtime.GOOS != "windows" {
		if err := os.Chown(dst, uid, gid); err != nil {
			return fmt.Errorf("chown %s: %w", dst, err)
		}
	}
	return nil
}

func copyDirPreserve(src, dst string) error {
	cmd := exec.Command("cp", "-Rp", src, dst)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("cp -Rp %s %s: %w (%s)", src, dst, err, strings.TrimSpace(string(out)))
	}
	return nil
}

func updateOLSVHost(username, domain, domainRoot string) error {
	vhostConf := filepath.Join("/usr/local/lsws/conf/vhosts", domain, "vhost.conf")
	data, err := os.ReadFile(vhostConf)
	if err != nil {
		return fmt.Errorf("read OLS vhost config %s: %w", vhostConf, err)
	}

	content := string(data)
	openBasedirLine := fmt.Sprintf("php_admin_value open_basedir %s/:/tmp/", domainRoot)

	// Update or insert open_basedir inside phpIniOverride
	reOpenBasedir := regexp.MustCompile(`(?m)^[ \t]*php_admin_value\s+open_basedir\s+.*$`)
	if reOpenBasedir.MatchString(content) {
		content = reOpenBasedir.ReplaceAllString(content, "  "+openBasedirLine)
	} else {
		rePhpIni := regexp.MustCompile(`(?m)^([ \t]*phpIniOverride\s*\{)`)
		if rePhpIni.MatchString(content) {
			content = rePhpIni.ReplaceAllString(content, "$1\n  "+openBasedirLine)
		} else {
			content += "\nphpIniOverride  {\n  " + openBasedirLine + "\n}\n"
		}
	}

	// Ensure Follow Symbolic Link is enabled
	reFollowSymlink := regexp.MustCompile(`(?m)^[ \t]*followSymbolLink\s+.*$`)
	if reFollowSymlink.MatchString(content) {
		content = reFollowSymlink.ReplaceAllString(content, "followSymbolLink          1")
	} else {
		content += "\nfollowSymbolLink          1\n"
	}

	if err := os.WriteFile(vhostConf, []byte(content), 0o644); err != nil {
		return fmt.Errorf("write OLS vhost config %s: %w", vhostConf, err)
	}

	cmd := exec.Command("/usr/local/lsws/bin/lswsctrl", "reload")
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("lswsctrl reload: %w (%s)", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func pathExists(p string) bool {
	_, err := os.Lstat(p)
	return err == nil
}

func fileExists(p string) bool {
	info, err := os.Stat(p)
	return err == nil && !info.IsDir()
}

func dirExists(p string) bool {
	info, err := os.Stat(p)
	return err == nil && info.IsDir()
}
