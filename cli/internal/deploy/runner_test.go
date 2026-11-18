package deploy

import (
	"os"
	"path/filepath"
	"testing"
)

func TestPurgeOldReleases_KeepsNewestAndActive(t *testing.T) {
	domainRoot := t.TempDir()
	releasesDir := filepath.Join(domainRoot, "releases")

	names := []string{
		"20261119_010000_aaa",
		"20261119_020000_bbb",
		"20261119_030000_ccc",
		"20261119_040000_ddd",
	}
	for _, n := range names {
		if err := os.MkdirAll(filepath.Join(releasesDir, n), 0o755); err != nil {
			t.Fatalf("mkdir %s: %v", n, err)
		}
	}

	active := filepath.Join(releasesDir, "20261119_010000_aaa")
	if err := purgeOldReleases(domainRoot, 2, active); err != nil {
		t.Fatalf("purgeOldReleases() error = %v", err)
	}

	// 010000_aaa must remain because it's active; 030000_ccc and 040000_ddd are newest 2.
	// 020000_bbb should be deleted.
	if _, err := os.Stat(filepath.Join(releasesDir, "20261119_020000_bbb")); !os.IsNotExist(err) {
		t.Fatalf("expected 20261119_020000_bbb to be deleted")
	}
	for _, keep := range []string{"20261119_010000_aaa", "20261119_030000_ccc", "20261119_040000_ddd"} {
		if _, err := os.Stat(filepath.Join(releasesDir, keep)); err != nil {
			t.Fatalf("expected %s to be kept, got err: %v", keep, err)
		}
	}
}
