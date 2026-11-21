package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type staticStateReader struct {
	state ReconcileState
	err   error
}

func (s staticStateReader) ReadState(_ context.Context) (ReconcileState, error) {
	return s.state, s.err
}

type mockReconcileRunner struct {
	commands []string
	failOn   string
	outputs  map[string]string
}

func (m *mockReconcileRunner) Run(_ context.Context, name string, args ...string) (string, error) {
	cmd := name + " " + strings.Join(args, " ")
	m.commands = append(m.commands, cmd)
	if m.failOn != "" && strings.Contains(cmd, m.failOn) {
		return "validation error", fmt.Errorf("simulated failure on %s", cmd)
	}
	if name == "postmap" && len(args) >= 2 && args[0] == "-F" {
		// Simulate postmap creating a .db file next to the map file.
		_ = os.WriteFile(args[1]+".db", []byte("mock-db"), 0o644)
	}
	if out, ok := m.outputs[cmd]; ok {
		return out, nil
	}
	return "", nil
}

func (m *mockReconcileRunner) LookPath(file string) (string, error) {
	return "/usr/bin/" + file, nil
}

func TestReconcileIdempotentAndSelectiveReload(t *testing.T) {
	root := t.TempDir()

	// Seed a valid DKIM private key on disk.
	dkimKeyPath := filepath.Join(root, "var", "lib", "rspamd", "dkim", "example.com.lc2026.key")
	if err := WriteFileAtomic(dkimKeyPath, []byte("PRIVATE-KEY-BYTES"), 0o600); err != nil {
		t.Fatal(err)
	}
	validHash, err := FileSHA256(dkimKeyPath)
	if err != nil {
		t.Fatal(err)
	}

	// Seed an old orphaned retired key past retention (10 days old).
	oldKeyPath := filepath.Join(root, "var", "lib", "rspamd", "dkim", "old.com.oldsel.key")
	if err := WriteFileAtomic(oldKeyPath, []byte("OLD-KEY"), 0o600); err != nil {
		t.Fatal(err)
	}
	tenDaysAgo := time.Now().Add(-10 * 24 * time.Hour)
	_ = os.Chtimes(oldKeyPath, tenDaysAgo, tenDaysAgo)

	// Seed a stale Dovecot SNI file.
	staleSni := filepath.Join(root, "etc", "dovecot", "conf.d", "sni", "stale.com.conf")
	if err := WriteFileAtomic(staleSni, []byte("# stale"), 0o644); err != nil {
		t.Fatal(err)
	}

	// Seed Postfix main.cf so filter scope can update milters.
	mainCf := filepath.Join(root, "etc", "postfix", "main.cf")
	if err := WriteFileAtomic(mainCf, []byte("smtpd_milters =\nnon_smtpd_milters = inet:127.0.0.1:11332\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	state := ReconcileState{
		Domains: []MailDomainState{
			{
				Domain:              "example.com",
				Active:              true,
				DkimEnabled:         true,
				SpamFilterEnabled:   true,
				SpamHeaderScore:     5.5,
				SpamRejectScore:     12.0,
				OutboundHourlyLimit: 150,
			},
		},
		DkimKeys: []DkimKeyState{
			{
				Domain:         "example.com",
				Selector:       "lc2026",
				Algorithm:      "rsa2048",
				PrivateKeyPath: "/var/lib/rspamd/dkim/example.com.lc2026.key",
				KeySHA256:      validHash,
				Status:         "active",
			},
		},
		Certs: map[string]CertPair{
			"example.com": {
				CertPath: "/etc/letsencrypt/live/mail.example.com/fullchain.pem",
				KeyPath:  "/etc/letsencrypt/live/mail.example.com/privkey.pem",
			},
		},
		FilterMode: "light",
	}

	runner1 := &mockReconcileRunner{}
	res1, err := Reconcile(context.Background(), ReconcileOptions{
		Scope:         "all",
		RootDir:       root,
		DovecotSeries: Dovecot24,
		Runner:        runner1,
		StateReader:   staticStateReader{state: state},
	})
	if err != nil {
		t.Fatalf("first reconcile failed: %v", err)
	}
	if len(res1.Reloaded) != 4 {
		t.Fatalf("expected all 4 daemons reloaded on initial run, got %v", res1.Reloaded)
	}
	if len(res1.Warnings) != 0 {
		t.Fatalf("expected 0 warnings, got %v", res1.Warnings)
	}

	// Verify stale SNI file and orphaned retired key were pruned.
	if _, err := os.Stat(staleSni); !os.IsNotExist(err) {
		t.Fatalf("expected stale SNI file to be pruned")
	}
	if _, err := os.Stat(oldKeyPath); !os.IsNotExist(err) {
		t.Fatalf("expected orphaned retired DKIM key to be pruned")
	}

	// Verify Dovecot 2.4 SNI syntax.
	sniBytes, err := os.ReadFile(filepath.Join(root, "etc", "dovecot", "conf.d", "sni", "example.com.conf"))
	if err != nil {
		t.Fatalf("read example.com.conf: %v", err)
	}
	if !strings.Contains(string(sniBytes), "ssl_server_cert_file =") {
		t.Fatalf("expected Dovecot 2.4 SNI syntax, got:\n%s", string(sniBytes))
	}

	// Second run with identical state must reload ZERO daemons.
	runner2 := &mockReconcileRunner{}
	res2, err := Reconcile(context.Background(), ReconcileOptions{
		Scope:         "all",
		RootDir:       root,
		DovecotSeries: Dovecot24,
		Runner:        runner2,
		StateReader:   staticStateReader{state: state},
	})
	if err != nil {
		t.Fatalf("second reconcile failed: %v", err)
	}
	if len(res2.Reloaded) != 0 {
		t.Fatalf("expected 0 daemon reloads on idempotent second run, got %v", res2.Reloaded)
	}
	if len(res2.Unchanged) != 4 {
		t.Fatalf("expected 4 unchanged daemons, got %v", res2.Unchanged)
	}
}

func TestReconcileRollsBackOnGateFailure(t *testing.T) {
	root := t.TempDir()
	srsPath := filepath.Join(root, "etc", "default", "postsrsd")
	original := "SRS_EXCLUDE_DOMAINS=original.com\n"
	if err := WriteFileAtomic(srsPath, []byte(original), 0o644); err != nil {
		t.Fatal(err)
	}

	runner := &mockReconcileRunner{
		failOn: "rspamadm configtest",
	}
	state := ReconcileState{
		Domains: []MailDomainState{{Domain: "new.com", Active: true}},
		Certs:   map[string]CertPair{},
	}

	_, err := Reconcile(context.Background(), ReconcileOptions{
		Scope:       "srs",
		RootDir:     root,
		Runner:      runner,
		StateReader: staticStateReader{state: state},
	})
	if err == nil {
		t.Fatalf("expected error when rspamadm configtest fails")
	}

	// Verify file was rolled back to original content.
	after, err := os.ReadFile(srsPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(after) != original {
		t.Fatalf("expected rollback to %q, got %q", original, string(after))
	}

	// Verify ZERO systemctl reload commands were issued.
	for _, cmd := range runner.commands {
		if strings.HasPrefix(cmd, "systemctl reload") {
			t.Fatalf("unexpected daemon reload after gate failure: %s", cmd)
		}
	}
}

func TestReconcileEmitsDkimDriftWarnings(t *testing.T) {
	root := t.TempDir()
	keyPath := filepath.Join(root, "var", "lib", "rspamd", "dkim", "drift.com.s1.key")
	if err := WriteFileAtomic(keyPath, []byte("TAMPERED"), 0o600); err != nil {
		t.Fatal(err)
	}

	state := ReconcileState{
		DkimKeys: []DkimKeyState{
			{
				Domain:         "drift.com",
				Selector:       "s1",
				PrivateKeyPath: "/var/lib/rspamd/dkim/drift.com.s1.key",
				KeySHA256:      "0000000000000000000000000000000000000000000000000000000000000000",
				Status:         "active",
			},
			{
				Domain:         "missing.com",
				Selector:       "s2",
				PrivateKeyPath: "/var/lib/rspamd/dkim/missing.com.s2.key",
				KeySHA256:      "abcd",
				Status:         "active",
			},
		},
	}

	runner := &mockReconcileRunner{}
	res, err := Reconcile(context.Background(), ReconcileOptions{
		Scope:       "dkim",
		RootDir:     root,
		Runner:      runner,
		StateReader: staticStateReader{state: state},
	})
	if err != nil {
		t.Fatalf("reconcile dkim failed: %v", err)
	}
	if len(res.Warnings) != 2 {
		t.Fatalf("expected 2 DKIM warnings (drift + missing), got %+v", res.Warnings)
	}
}
