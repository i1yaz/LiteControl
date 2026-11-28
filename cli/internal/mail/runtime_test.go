package mail

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestEvictSession(t *testing.T) {
	runner := &mockReconcileRunner{}
	res, err := EvictSession(context.Background(), EvictSessionOptions{
		Email:  "Alice@Example.com",
		Runner: runner,
	})
	if err != nil {
		t.Fatalf("EvictSession failed: %v", err)
	}
	if res.Email != "alice@example.com" || res.Status != "success" {
		t.Fatalf("unexpected result: %+v", res)
	}
	if len(runner.commands) != 2 ||
		runner.commands[0] != "doveadm auth cache flush alice@example.com" ||
		runner.commands[1] != "doveadm kick alice@example.com" {
		t.Fatalf("unexpected commands: %v", runner.commands)
	}

	if _, err := EvictSession(context.Background(), EvictSessionOptions{
		Email:  "bad..email@example.com",
		Runner: runner,
	}); err == nil {
		t.Fatalf("expected error for invalid email")
	}
}

func TestPurgeMailboxContainment(t *testing.T) {
	root := t.TempDir()
	mailboxDir := filepath.Join(root, "var", "vmail", "example.com", "alice", "Maildir")
	if err := os.MkdirAll(mailboxDir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(mailboxDir, "msg.eml"), []byte("hello"), 0o640); err != nil {
		t.Fatal(err)
	}

	// Reject traversal attempts.
	if _, err := PurgeMailbox(context.Background(), PurgeMailboxOptions{
		Domain:  "example.com",
		User:    "../etc",
		RootDir: root,
	}); err == nil {
		t.Fatalf("expected traversal user to be rejected")
	}
	if _, err := PurgeMailbox(context.Background(), PurgeMailboxOptions{
		Domain:  "../etc",
		User:    "alice",
		RootDir: root,
	}); err == nil {
		t.Fatalf("expected traversal domain to be rejected")
	}

	// Valid purge succeeds.
	res, err := PurgeMailbox(context.Background(), PurgeMailboxOptions{
		Domain:  "example.com",
		User:    "alice",
		RootDir: root,
	})
	if err != nil {
		t.Fatalf("PurgeMailbox failed: %v", err)
	}
	if !res.Removed {
		t.Fatalf("expected Removed=true")
	}
	if _, err := os.Stat(filepath.Join(root, "var", "vmail", "example.com", "alice")); !os.IsNotExist(err) {
		t.Fatalf("expected mailbox directory to be deleted")
	}

	// Second purge on already-removed mailbox is idempotent (Removed=false).
	res2, err := PurgeMailbox(context.Background(), PurgeMailboxOptions{
		Domain:  "example.com",
		User:    "alice",
		RootDir: root,
	})
	if err != nil {
		t.Fatalf("second PurgeMailbox failed: %v", err)
	}
	if res2.Removed {
		t.Fatalf("expected Removed=false on non-existent mailbox")
	}
}

func TestQuotaUsageParsing(t *testing.T) {
	tabOutput := "Username\tQuota name\tType\tValue\tLimit\t%\n" +
		"alice@example.com\tUser quota\tSTORAGE\t1024\t1048576\t0\n" +
		"alice@example.com\tUser quota\tMESSAGE\t42\t-\t0\n" +
		"bob@example.com\tUser quota\tSTORAGE\t512\t1048576\t0\n"

	runner := &mockReconcileRunner{
		outputs: map[string]string{
			"doveadm -f tab quota get -u *@example.com": tabOutput,
		},
	}

	usage, err := QuotaUsage(context.Background(), QuotaUsageOptions{
		Domain: "example.com",
		Runner: runner,
	})
	if err != nil {
		t.Fatalf("QuotaUsage failed: %v", err)
	}
	if usage["alice@example.com"] != 1024*1024 {
		t.Fatalf("expected 1048576 bytes for alice, got %d", usage["alice@example.com"])
	}
	if usage["bob@example.com"] != 512*1024 {
		t.Fatalf("expected 524288 bytes for bob, got %d", usage["bob@example.com"])
	}
}

func TestPurgeDomainRemovesMaildirAndDkimKeysOnlyForTargetDomain(t *testing.T) {
	root := t.TempDir()
	targetMaildir := filepath.Join(root, "var", "vmail", "example.com", "alice")
	otherMaildir := filepath.Join(root, "var", "vmail", "other.com", "bob")
	dkimDir := filepath.Join(root, "var", "lib", "rspamd", "dkim")

	for _, dir := range []string{targetMaildir, otherMaildir, dkimDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("mkdir %s: %v", dir, err)
		}
	}

	targetKey1 := filepath.Join(dkimDir, "example.com.lc202601.key")
	targetKey2 := filepath.Join(dkimDir, "example.com.lc202602.key")
	otherKey := filepath.Join(dkimDir, "other.com.lc202601.key")
	for _, f := range []string{targetKey1, targetKey2, otherKey} {
		if err := os.WriteFile(f, []byte("private-key"), 0o600); err != nil {
			t.Fatalf("write %s: %v", f, err)
		}
	}

	res, err := PurgeDomain(context.Background(), PurgeDomainOptions{
		Domain:  "example.com",
		RootDir: root,
	})
	if err != nil {
		t.Fatalf("PurgeDomain failed: %v", err)
	}
	if !res.Removed {
		t.Fatalf("expected Removed=true")
	}
	if len(res.DkimKeysRemoved) != 2 {
		t.Fatalf("expected 2 DKIM keys removed, got %d", len(res.DkimKeysRemoved))
	}
	if _, err := os.Stat(filepath.Join(root, "var", "vmail", "example.com")); !os.IsNotExist(err) {
		t.Fatalf("expected example.com maildir to be removed")
	}
	if _, err := os.Stat(otherMaildir); err != nil {
		t.Fatalf("expected other.com maildir to be preserved: %v", err)
	}
	if _, err := os.Stat(otherKey); err != nil {
		t.Fatalf("expected other.com DKIM key to be preserved: %v", err)
	}
}
