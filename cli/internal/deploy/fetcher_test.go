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

func TestResolveCommitMetaAPIURL(t *testing.T) {
	tests := []struct {
		name         string
		tarballURL   string
		wantProvider string
		wantAPI      string
	}{
		{
			name:         "github",
			tarballURL:   "https://api.github.com/repos/org/app/tarball/main",
			wantProvider: "github",
			wantAPI:      "https://api.github.com/repos/org/app/commits/main",
		},
		{
			name:         "gitlab",
			tarballURL:   "https://gitlab.com/api/v4/projects/org%2Fapp/repository/archive.tar.gz?sha=main",
			wantProvider: "gitlab",
			wantAPI:      "https://gitlab.com/api/v4/projects/org%2Fapp/repository/commits/main",
		},
		{
			name:         "bitbucket",
			tarballURL:   "https://bitbucket.org/org/app/get/main.tar.gz",
			wantProvider: "bitbucket",
			wantAPI:      "https://api.bitbucket.org/2.0/repositories/org/app/commits/main?pagelen=1",
		},
		{
			name:         "generic",
			tarballURL:   "https://example.com/releases/app.tar.gz",
			wantProvider: "",
			wantAPI:      "",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			gotProvider, gotAPI := resolveCommitMetaAPIURL(tc.tarballURL)
			if gotProvider != tc.wantProvider || gotAPI != tc.wantAPI {
				t.Fatalf("got (%q, %q), want (%q, %q)", gotProvider, gotAPI, tc.wantProvider, tc.wantAPI)
			}
		})
	}
}

func TestParseProviderCommitMeta(t *testing.T) {
	gh := parseProviderCommitMeta("github", []byte(`{
		"sha": "abc1234567",
		"commit": {
			"message": "feat: deploy tarball\n\nmore details",
			"author": {"name": "Alice Smith"}
		},
		"author": {"login": "alice"}
	}`))
	if gh.SHA != "abc1234567" || gh.Message != "feat: deploy tarball" || gh.Author != "Alice Smith" {
		t.Fatalf("unexpected github meta: %+v", gh)
	}

	gl := parseProviderCommitMeta("gitlab", []byte(`{
		"id": "def7654321",
		"title": "fix: gitlab release",
		"author_name": "Bob Jones"
	}`))
	if gl.SHA != "def7654321" || gl.Message != "fix: gitlab release" || gl.Author != "Bob Jones" {
		t.Fatalf("unexpected gitlab meta: %+v", gl)
	}

	bb := parseProviderCommitMeta("bitbucket", []byte(`{
		"values": [{
			"hash": "99887766",
			"message": "chore: bitbucket\nbody",
			"author": {"raw": "Carol <c@example.com>", "user": {"display_name": "Carol"}}
		}]
	}`))
	if bb.SHA != "99887766" || bb.Message != "chore: bitbucket" || bb.Author != "Carol" {
		t.Fatalf("unexpected bitbucket meta: %+v", bb)
	}
}
