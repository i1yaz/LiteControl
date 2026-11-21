package mail

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
)

type fakeDkimRunner struct {
	keyContent string
	stdout     string
	lastName   string
	lastArgs   []string
}

func (f *fakeDkimRunner) LookPath(file string) (string, error) {
	return "/usr/bin/" + file, nil
}

func (f *fakeDkimRunner) Run(_ context.Context, name string, args ...string) (string, error) {
	f.lastName = name
	f.lastArgs = append([]string(nil), args...)

	for i := 0; i < len(args)-1; i++ {
		if args[i] == "-k" {
			if err := os.WriteFile(args[i+1], []byte(f.keyContent), 0o600); err != nil {
				return "", err
			}
		}
	}

	return f.stdout, nil
}

func TestDkimGenerateSuccess(t *testing.T) {
	root := t.TempDir()
	keyContent := "-----BEGIN PRIVATE KEY-----\nFAKEKEYDATA\n-----END PRIVATE KEY-----\n"
	expectedSum := sha256.Sum256([]byte(keyContent))

	runner := &fakeDkimRunner{
		keyContent: keyContent,
		stdout: `lc2026._domainkey IN TXT ( "v=DKIM1; k=rsa; "
	"p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey" ) ;`,
	}

	res, err := DkimGenerate(context.Background(), DkimGenerateOptions{
		Domain:    "example.com",
		Selector:  "lc2026",
		Algorithm: "rsa2048",
		RootDir:   root,
		Runner:    runner,
	})
	if err != nil {
		t.Fatalf("DkimGenerate failed: %v", err)
	}

	if runner.lastName != "rspamadm" {
		t.Fatalf("expected rspamadm binary, got %q", runner.lastName)
	}
	if res.PublicKeyTxt != "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey" {
		t.Fatalf("unexpected public_key_txt: %q", res.PublicKeyTxt)
	}
	if res.KeySha256 != hex.EncodeToString(expectedSum[:]) {
		t.Fatalf("unexpected key_sha256: got %q, want %q", res.KeySha256, hex.EncodeToString(expectedSum[:]))
	}
	expectedPath := filepath.Join(root, "var", "lib", "rspamd", "dkim", "example.com.lc2026.key")
	if res.PrivateKeyPath != expectedPath {
		t.Fatalf("unexpected private_key_path: got %q, want %q", res.PrivateKeyPath, expectedPath)
	}
}

func TestDkimGenerateRejectsInvalidInputs(t *testing.T) {
	root := t.TempDir()

	if _, err := DkimGenerate(context.Background(), DkimGenerateOptions{
		Domain:   "../etc",
		Selector: "lc2026",
		RootDir:  root,
	}); err == nil {
		t.Fatal("expected error for invalid domain")
	}

	if _, err := DkimGenerate(context.Background(), DkimGenerateOptions{
		Domain:   "example.com",
		Selector: "INVALID_SELECTOR!",
		RootDir:  root,
	}); err == nil {
		t.Fatal("expected error for invalid selector")
	}

	if _, err := DkimGenerate(context.Background(), DkimGenerateOptions{
		Domain:    "example.com",
		Selector:  "lc2026",
		Algorithm: "md5",
		RootDir:   root,
	}); err == nil {
		t.Fatal("expected error for unsupported algorithm")
	}
}

func TestDkimRemoveSuccessAndReconciles(t *testing.T) {
	root := t.TempDir()
	dkimDir := filepath.Join(root, "var", "lib", "rspamd", "dkim")
	if err := os.MkdirAll(dkimDir, 0o750); err != nil {
		t.Fatal(err)
	}
	keyPath := filepath.Join(dkimDir, "example.com.lc2026.key")
	if err := os.WriteFile(keyPath, []byte("secret"), 0o600); err != nil {
		t.Fatal(err)
	}

	reconciledScope := ""
	res, err := DkimRemove(context.Background(), DkimRemoveOptions{
		Domain:   "example.com",
		Selector: "lc2026",
		RootDir:  root,
		ReconcileFunc: func(_ context.Context, scope string) error {
			reconciledScope = scope
			return nil
		},
	})
	if err != nil {
		t.Fatalf("DkimRemove failed: %v", err)
	}
	if !res.Removed {
		t.Fatal("expected Removed=true")
	}
	if reconciledScope != "dkim" {
		t.Fatalf("expected reconcile scope 'dkim', got %q", reconciledScope)
	}
	if _, err := os.Stat(keyPath); !os.IsNotExist(err) {
		t.Fatalf("expected key file to be removed")
	}

	// Second call is idempotent.
	res2, err := DkimRemove(context.Background(), DkimRemoveOptions{
		Domain:        "example.com",
		Selector:      "lc2026",
		RootDir:       root,
		SkipReconcile: true,
	})
	if err != nil {
		t.Fatalf("second DkimRemove failed: %v", err)
	}
	if res2.Removed {
		t.Fatal("expected Removed=false on missing key")
	}
}
