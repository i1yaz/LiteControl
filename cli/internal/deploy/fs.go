package deploy

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

var ErrInvalidHomePath = errors.New("path must be inside /home/")

func validateHomePath(p string) error {
	cleaned := filepath.ToSlash(filepath.Clean(p))
	if !strings.HasPrefix(cleaned, "/home/") || cleaned == "/home/" {
		return fmt.Errorf("%w: %s", ErrInvalidHomePath, p)
	}
	return nil
}

// AtomicSymlink creates a temporary symlink, sets ownership, and atomically
// replaces linkPath via os.Rename (rename(2) / mv -Tf).
func AtomicSymlink(targetPath string, linkPath string, uid int, gid int) error {
	if err := validateHomePath(targetPath); err != nil {
		return fmt.Errorf("invalid targetPath: %w", err)
	}
	if err := validateHomePath(linkPath); err != nil {
		return fmt.Errorf("invalid linkPath: %w", err)
	}

	return atomicSymlinkUnchecked(targetPath, linkPath, uid, gid)
}

func atomicSymlinkUnchecked(targetPath string, linkPath string, uid int, gid int) error {
	tmpLink := linkPath + ".tmp." + strconv.FormatInt(time.Now().UnixNano(), 10)

	if err := os.Symlink(targetPath, tmpLink); err != nil {
		return fmt.Errorf("create temp symlink %s: %w", tmpLink, err)
	}

	if runtime.GOOS != "windows" {
		if err := os.Lchown(tmpLink, uid, gid); err != nil {
			_ = os.Remove(tmpLink)
			return fmt.Errorf("lchown temp symlink %s: %w", tmpLink, err)
		}
	}

	if err := os.Rename(tmpLink, linkPath); err != nil {
		_ = os.Remove(tmpLink)
		return fmt.Errorf("atomic rename %s -> %s: %w", tmpLink, linkPath, err)
	}

	return nil
}
