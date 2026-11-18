package deploy

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"testing"
)

func TestParseCommitMeta(t *testing.T) {
	meta := parseCommitMeta("a1b2c3d4e5f6|feat: add zero-downtime deploy|Jane Doe\n")
	if meta.SHA != "a1b2c3d4e5f6" {
		t.Fatalf("expected SHA a1b2c3d4e5f6, got %q", meta.SHA)
	}
	if meta.Message != "feat: add zero-downtime deploy" {
		t.Fatalf("expected Message, got %q", meta.Message)
	}
	if meta.Author != "Jane Doe" {
		t.Fatalf("expected Author Jane Doe, got %q", meta.Author)
	}
}

func TestRemoveAndVerifyNoGit(t *testing.T) {
	releaseDir := t.TempDir()
	gitDir := filepath.Join(releaseDir, ".git")
	if err := os.MkdirAll(filepath.Join(gitDir, "objects"), 0o755); err != nil {
		t.Fatalf("mkdir .git: %v", err)
	}
	if err := os.WriteFile(filepath.Join(gitDir, "HEAD"), []byte("ref: refs/heads/main\n"), 0o644); err != nil {
		t.Fatalf("write HEAD: %v", err)
	}

	if err := removeAndVerifyNoGit(releaseDir); err != nil {
		t.Fatalf("removeAndVerifyNoGit failed: %v", err)
	}

	if _, err := os.Stat(gitDir); !os.IsNotExist(err) {
		t.Fatalf("expected .git to be removed, stat err: %v", err)
	}
}

func TestFetchReleaseCode_UnsupportedDriver(t *testing.T) {
	spec := Payload{
		Repo: RepoConfig{Driver: "svn"},
	}
	_, err := FetchReleaseCode(context.Background(), spec, t.TempDir(), io.Discard)
	if err == nil {
		t.Fatal("expected error for unsupported driver, got nil")
	}
}
