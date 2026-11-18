package deploy

import (
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
)

// LinkSharedPaths links spec.SharedDirs and spec.SharedFiles from
// <domainRoot>/shared/ into releaseDir using AtomicSymlink.
func LinkSharedPaths(spec Payload, releaseDir string, uid int, gid int) error {
	sharedRoot := filepath.Join(spec.DomainRoot, "shared")

	for _, relDir := range spec.SharedDirs {
		if err := linkSharedDir(sharedRoot, releaseDir, relDir, uid, gid); err != nil {
			return fmt.Errorf("link shared dir %q: %w", relDir, err)
		}
	}

	for _, relFile := range spec.SharedFiles {
		if err := linkSharedFile(sharedRoot, releaseDir, relFile, uid, gid); err != nil {
			return fmt.Errorf("link shared file %q: %w", relFile, err)
		}
	}

	return nil
}

func linkSharedDir(sharedRoot, releaseDir, relDir string, uid, gid int) error {
	sharedTarget := filepath.Join(sharedRoot, relDir)
	releaseTarget := filepath.Join(releaseDir, relDir)

	if _, err := os.Stat(sharedTarget); os.IsNotExist(err) {
		if info, relErr := os.Stat(releaseTarget); relErr == nil && info.IsDir() {
			if err := copyDirNoClobber(releaseTarget, sharedTarget, uid, gid); err != nil {
				return fmt.Errorf("seed shared dir from release: %w", err)
			}
		} else {
			if err := os.MkdirAll(sharedTarget, 0o755); err != nil {
				return fmt.Errorf("create shared dir %s: %w", sharedTarget, err)
			}
			if err := chownIfUnix(sharedTarget, uid, gid); err != nil {
				return err
			}
		}
	} else if err != nil {
		return fmt.Errorf("stat shared dir %s: %w", sharedTarget, err)
	}

	if err := os.RemoveAll(releaseTarget); err != nil {
		return fmt.Errorf("remove release dir %s: %w", releaseTarget, err)
	}
	if err := os.MkdirAll(filepath.Dir(releaseTarget), 0o755); err != nil {
		return fmt.Errorf("create parent dir for %s: %w", releaseTarget, err)
	}

	return AtomicSymlink(sharedTarget, releaseTarget, uid, gid)
}

func linkSharedFile(sharedRoot, releaseDir, relFile string, uid, gid int) error {
	sharedFile := filepath.Join(sharedRoot, relFile)
	releaseFile := filepath.Join(releaseDir, relFile)

	if _, err := os.Stat(sharedFile); os.IsNotExist(err) {
		if err := os.MkdirAll(filepath.Dir(sharedFile), 0o755); err != nil {
			return fmt.Errorf("create parent dir for %s: %w", sharedFile, err)
		}

		envExample := filepath.Join(releaseDir, ".env.example")
		if filepath.Base(relFile) == ".env" && fileExists(envExample) {
			if err := copyFile(envExample, sharedFile, 0o600); err != nil {
				return fmt.Errorf("copy .env.example to %s: %w", sharedFile, err)
			}
		} else {
			f, err := os.OpenFile(sharedFile, os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0o600)
			if err != nil {
				return fmt.Errorf("create empty shared file %s: %w", sharedFile, err)
			}
			_ = f.Close()
		}

		if err := chownIfUnix(sharedFile, uid, gid); err != nil {
			return err
		}
	} else if err != nil {
		return fmt.Errorf("stat shared file %s: %w", sharedFile, err)
	}

	if err := os.RemoveAll(releaseFile); err != nil {
		return fmt.Errorf("remove release file %s: %w", releaseFile, err)
	}
	if err := os.MkdirAll(filepath.Dir(releaseFile), 0o755); err != nil {
		return fmt.Errorf("create parent dir for %s: %w", releaseFile, err)
	}

	return AtomicSymlink(sharedFile, releaseFile, uid, gid)
}

func copyDirNoClobber(src, dst string, uid, gid int) error {
	return filepath.WalkDir(src, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}

		rel, err := filepath.Rel(src, path)
		if err != nil {
			return err
		}
		target := filepath.Join(dst, rel)

		if d.IsDir() {
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
			return chownIfUnix(target, uid, gid)
		}

		if fileExists(target) {
			return nil
		}

		info, err := d.Info()
		if err != nil {
			return err
		}
		if err := copyFile(path, target, info.Mode().Perm()); err != nil {
			return err
		}
		return chownIfUnix(target, uid, gid)
	})
}

func copyFile(src, dst string, perm fs.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, perm)
	if err != nil {
		return err
	}
	defer out.Close()

	if _, err := io.Copy(out, in); err != nil {
		return err
	}
	return out.Close()
}

func chownIfUnix(path string, uid, gid int) error {
	if runtime.GOOS == "windows" {
		return nil
	}
	if err := os.Chown(path, uid, gid); err != nil {
		return fmt.Errorf("chown %s: %w", path, err)
	}
	return nil
}
