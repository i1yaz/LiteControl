package mail

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func mustWriteFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatalf("mkdir %s: %v", path, err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("write %s: %v", path, err)
	}
}

func TestFilterEnableAndDemoteLifecycle(t *testing.T) {
	root := t.TempDir()

	// Seed Light-mode configs & a DKIM private key that must never be touched.
	mustWriteFile(t, filepath.Join(root, "etc/postfix/main.cf"), "smtpd_milters =\nnon_smtpd_milters = inet:127.0.0.1:11332\n")
	mustWriteFile(t, filepath.Join(root, "etc/lc-rspamd-redis/redis.conf"), "maxmemory 32mb\nmaxmemory-policy volatile-ttl\n")
	mustWriteFile(t, filepath.Join(root, "etc/rspamd/local.d/dkim_signing.conf"), "sign_authenticated = true;\n")
	mustWriteFile(t, filepath.Join(root, "var/lib/rspamd/dkim/example.com.s1.key"), "PRIVATE-KEY-DATA")

	runner := &fakeRunner{}
	reconcileCalled := ""

	// 1. Enable full Rspamd anti-spam (without ClamAV) on Dovecot 2.4.
	res, err := FilterEnable(context.Background(), FilterEnableOptions{
		Driver:        "rspamd",
		WithClamAV:    false,
		RootDir:       root,
		DovecotSeries: Dovecot24,
		SkipPackages:  true,
		Runner:        runner,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			reconcileCalled = scope
			return nil
		},
	})
	if err != nil {
		t.Fatalf("FilterEnable failed: %v", err)
	}
	if res.Mode != "full" || res.RedisMemory != RedisMaxMemoryFull || res.SmtpdMilters != RspamdMilterAddress {
		t.Fatalf("unexpected FilterEnableResult: %+v", res)
	}
	if reconcileCalled != "filter" {
		t.Fatalf("expected reconcile scope=filter, got %q", reconcileCalled)
	}

	// Verify Redis raised to 256mb.
	redisBytes, _ := os.ReadFile(filepath.Join(root, "etc/lc-rspamd-redis/redis.conf"))
	if !strings.Contains(string(redisBytes), "maxmemory 256mb") {
		t.Fatalf("expected maxmemory 256mb, got:\n%s", string(redisBytes))
	}

	// Verify Postfix smtpd_milters enabled.
	mainCfBytes, _ := os.ReadFile(filepath.Join(root, "etc/postfix/main.cf"))
	if !strings.Contains(string(mainCfBytes), "smtpd_milters = inet:127.0.0.1:11332") {
		t.Fatalf("expected smtpd_milters enabled, got:\n%s", string(mainCfBytes))
	}

	// Verify Dovecot 2.4 Sieve Junk drop-in rendered.
	sieveConfBytes, _ := os.ReadFile(filepath.Join(root, "etc/dovecot/conf.d/95-lc-sieve-spam.conf"))
	if !strings.Contains(string(sieveConfBytes), "sieve_script before_spam") {
		t.Fatalf("expected Dovecot 2.4 sieve_script syntax, got:\n%s", string(sieveConfBytes))
	}

	// 2. Demote back to Light mode and verify DKIM key + config are preserved.
	reconcileCalled = ""
	demoteRes, err := FilterDemote(context.Background(), FilterDemoteOptions{
		Driver:  "rspamd",
		RootDir: root,
		Runner:  runner,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			reconcileCalled = scope
			return nil
		},
	})
	if err != nil {
		t.Fatalf("FilterDemote failed: %v", err)
	}
	if demoteRes.Mode != "light" || demoteRes.RedisMemory != RedisMaxMemoryLight || !demoteRes.DkimPreserved {
		t.Fatalf("unexpected FilterDemoteResult: %+v", demoteRes)
	}
	if reconcileCalled != "filter" {
		t.Fatalf("expected reconcile scope=filter on demote, got %q", reconcileCalled)
	}

	redisAfter, _ := os.ReadFile(filepath.Join(root, "etc/lc-rspamd-redis/redis.conf"))
	if !strings.Contains(string(redisAfter), "maxmemory 32mb") {
		t.Fatalf("expected maxmemory 32mb after demote, got:\n%s", string(redisAfter))
	}

	mainCfAfter, _ := os.ReadFile(filepath.Join(root, "etc/postfix/main.cf"))
	if !strings.Contains(string(mainCfAfter), "smtpd_milters =\n") {
		t.Fatalf("expected empty smtpd_milters after demote, got:\n%s", string(mainCfAfter))
	}
	if !strings.Contains(string(mainCfAfter), "non_smtpd_milters = inet:127.0.0.1:11332") {
		t.Fatalf("expected non_smtpd_milters preserved after demote, got:\n%s", string(mainCfAfter))
	}

	if _, err := os.Stat(filepath.Join(root, "var/lib/rspamd/dkim/example.com.s1.key")); err != nil {
		t.Fatalf("DKIM private key must be preserved on demote: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "etc/rspamd/local.d/dkim_signing.conf")); err != nil {
		t.Fatalf("dkim_signing.conf must be preserved on demote: %v", err)
	}
}

func TestFilterEnableClamAVRamPreFlight(t *testing.T) {
	root := t.TempDir()
	runner := &fakeRunner{}

	// Reject < 4096 MB RAM.
	_, err := FilterEnable(context.Background(), FilterEnableOptions{
		Driver:        "rspamd",
		WithClamAV:    true,
		RootDir:       root,
		SkipPackages:  true,
		SkipReconcile: true,
		Runner:        runner,
		TotalMemoryMB: func() (int, error) { return 2048, nil },
	})
	if err == nil || !strings.Contains(err.Error(), "insufficient RAM") {
		t.Fatalf("expected insufficient RAM error, got: %v", err)
	}

	// Succeed with >= 4096 MB RAM and write antivirus.conf.
	res, err := FilterEnable(context.Background(), FilterEnableOptions{
		Driver:        "rspamd",
		WithClamAV:    true,
		RootDir:       root,
		DovecotSeries: Dovecot23,
		SkipPackages:  true,
		SkipReconcile: true,
		Runner:        runner,
		TotalMemoryMB: func() (int, error) { return 4096, nil },
	})
	if err != nil {
		t.Fatalf("expected ClamAV enable to succeed at 4096 MB: %v", err)
	}
	if !res.WithClamAV {
		t.Fatalf("expected WithClamAV=true")
	}
	if _, err := os.Stat(filepath.Join(root, "etc/rspamd/local.d/antivirus.conf")); err != nil {
		t.Fatalf("expected antivirus.conf to be written: %v", err)
	}
}
