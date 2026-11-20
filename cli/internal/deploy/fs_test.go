package deploy

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestAtomicSymlink_ValidatesHomePath(t *testing.T) {
	err := AtomicSymlink("/etc/passwd", "/home/client1/example.com/current", 1000, 1000)
	if !errors.Is(err, ErrInvalidHomePath) {
		t.Fatalf("expected ErrInvalidHomePath for targetPath, got: %v", err)
	}

	err = AtomicSymlink("/home/client1/example.com/releases/r1", "/var/www/current", 1000, 1000)
	if !errors.Is(err, ErrInvalidHomePath) {
		t.Fatalf("expected ErrInvalidHomePath for linkPath, got: %v", err)
	}

	err = AtomicSymlink("/home/", "/home/client1/example.com/current", 1000, 1000)
	if !errors.Is(err, ErrInvalidHomePath) {
		t.Fatalf("expected ErrInvalidHomePath for /home/, got: %v", err)
	}
}

func TestAtomicSymlinkUnchecked_ReplacesExistingSymlink(t *testing.T) {
	tmpDir := t.TempDir()

	release1 := filepath.Join(tmpDir, "release1")
	release2 := filepath.Join(tmpDir, "release2")
	if err := os.MkdirAll(release1, 0o755); err != nil {
		t.Fatalf("mkdir release1: %v", err)
	}
	if err := os.MkdirAll(release2, 0o755); err != nil {
		t.Fatalf("mkdir release2: %v", err)
	}

	currentLink := filepath.Join(tmpDir, "current")

	if err := atomicSymlinkUnchecked(release1, currentLink, -1, -1); err != nil {
		if runtime.GOOS == "windows" && strings.Contains(err.Error(), "A required privilege is not held by the client") {
			t.Skip("skipping symlink test on Windows without symlink privilege")
		}
		t.Fatalf("initial atomicSymlinkUnchecked failed: %v", err)
	}

	got1, err := os.Readlink(currentLink)
	if err != nil {
		t.Fatalf("readlink 1: %v", err)
	}
	if got1 != release1 {
		t.Fatalf("expected %s, got %s", release1, got1)
	}

	if err := atomicSymlinkUnchecked(release2, currentLink, -1, -1); err != nil {
		t.Fatalf("replacement atomicSymlinkUnchecked failed: %v", err)
	}

	got2, err := os.Readlink(currentLink)
	if err != nil {
		t.Fatalf("readlink 2: %v", err)
	}
	if got2 != release2 {
		t.Fatalf("expected %s, got %s", release2, got2)
	}
}
