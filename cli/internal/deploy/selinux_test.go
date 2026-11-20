package deploy

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestIsSelinuxEnforcingFrom(t *testing.T) {
	tmpDir := t.TempDir()

	enforcingFile := filepath.Join(tmpDir, "enforce_1")
	if err := os.WriteFile(enforcingFile, []byte("1\n"), 0o644); err != nil {
		t.Fatalf("write enforce_1: %v", err)
	}
	if !isSelinuxEnforcingFrom(enforcingFile, nil) {
		t.Fatal("expected true when enforce file contains 1")
	}

	permissiveFile := filepath.Join(tmpDir, "enforce_0")
	if err := os.WriteFile(permissiveFile, []byte("0\n"), 0o644); err != nil {
		t.Fatalf("write enforce_0: %v", err)
	}
	if isSelinuxEnforcingFrom(permissiveFile, func() (string, error) { return "Enforcing", nil }) {
		t.Fatal("expected false when enforce file contains 0")
	}

	missingFile := filepath.Join(tmpDir, "missing")
	if !isSelinuxEnforcingFrom(missingFile, func() (string, error) { return "Enforcing\n", nil }) {
		t.Fatal("expected true when enforce file is missing and getenforce returns Enforcing")
	}
	if isSelinuxEnforcingFrom(missingFile, func() (string, error) { return "Permissive\n", nil }) {
		t.Fatal("expected false when getenforce returns Permissive")
	}
	if isSelinuxEnforcingFrom(missingFile, func() (string, error) { return "", errors.New("not found") }) {
		t.Fatal("expected false when enforce file is missing and getenforce fails")
	}
}

func TestRestoreContextSkipsWhenNotEnforcing(t *testing.T) {
	if err := RestoreContext(""); err != nil {
		t.Fatalf("expected nil for empty path, got %v", err)
	}
	if err := RestoreContext(t.TempDir()); err != nil {
		t.Fatalf("expected nil when SELinux is not enforcing, got %v", err)
	}
}
