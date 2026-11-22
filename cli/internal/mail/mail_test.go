package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type fakeRunner struct {
	calls    []string
	handlers map[string]func(args ...string) (string, error)
	binaries map[string]string
}

func (f *fakeRunner) Run(_ context.Context, name string, args ...string) (string, error) {
	key := name + " " + strings.Join(args, " ")
	f.calls = append(f.calls, strings.TrimSpace(key))

	if fn, ok := f.handlers[strings.TrimSpace(key)]; ok {
		return fn(args...)
	}
	if fn, ok := f.handlers[name]; ok {
		return fn(args...)
	}
	return "", nil
}

func (f *fakeRunner) LookPath(file string) (string, error) {
	if p, ok := f.binaries[file]; ok {
		return p, nil
	}
	return "", fmt.Errorf("not found: %s", file)
}

func TestParseOSRelease(t *testing.T) {
	cases := []struct {
		name       string
		content    string
		wantFamily OSFamily
		wantErr    bool
	}{
		{"debian12", "ID=debian\nVERSION_ID=\"12\"\nVERSION_CODENAME=bookworm\n", OSFamilyDebian, false},
		{"debian13", "ID=debian\nVERSION_ID=\"13\"\nVERSION_CODENAME=trixie\n", OSFamilyDebian, false},
		{"ubuntu2204", "ID=ubuntu\nVERSION_ID=\"22.04\"\nVERSION_CODENAME=jammy\n", OSFamilyDebian, false},
		{"ubuntu2404", "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n", OSFamilyDebian, false},
		{"ubuntu2604", "ID=ubuntu\nVERSION_ID=\"26.04\"\nVERSION_CODENAME=resolute\n", OSFamilyDebian, false},
		{"rocky9", "ID=\"rocky\"\nVERSION_ID=\"9.4\"\n", OSFamilyRHEL, false},
		{"alma10", "ID=\"almalinux\"\nVERSION_ID=\"10.0\"\n", OSFamilyRHEL, false},
		{"unsupported", "ID=arch\nVERSION_ID=\"rolling\"\n", "", true},
		{"ubuntu2004", "ID=ubuntu\nVERSION_ID=\"20.04\"\n", "", true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			info, err := ParseOSRelease(tc.content)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("expected error for %s, got nil", tc.name)
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if info.Family != tc.wantFamily {
				t.Fatalf("got family %q, want %q", info.Family, tc.wantFamily)
			}
		})
	}
}

func TestProbeHelpers(t *testing.T) {
	if _, err := CheckPostfixVersion("3.8.6"); err != nil {
		t.Fatalf("expected Postfix 3.8.6 to pass: %v", err)
	}
	if _, err := CheckPostfixVersion("3.3.1"); err == nil {
		t.Fatalf("expected Postfix 3.3.1 to fail")
	}

	if _, s, err := DetectDovecotSeries("2.3.21 (47349e2482)"); err != nil || s != Dovecot23 {
		t.Fatalf("expected Dovecot 2.3, got %q err=%v", s, err)
	}
	if _, s, err := DetectDovecotSeries("2.4.1 (abc123)"); err != nil || s != Dovecot24 {
		t.Fatalf("expected Dovecot 2.4, got %q err=%v", s, err)
	}

	if got := DetectPasswordScheme("CRYPT MD5 SHA512-CRYPT ARGON2ID"); got != SchemeArgon2ID {
		t.Fatalf("expected ARGON2ID, got %s", got)
	}
	if got := DetectPasswordScheme("CRYPT MD5 SHA512-CRYPT"); got != SchemeSHA512Crypt {
		t.Fatalf("expected SHA512-CRYPT fallback, got %s", got)
	}
}

func TestRenderAllConfigsDovecot23And24(t *testing.T) {
	for _, series := range []DovecotSeries{Dovecot23, Dovecot24} {
		t.Run(string(series), func(t *testing.T) {
			files, err := RenderAllConfigs(TemplateData{
				Hostname:       "mail.example.com",
				DBHost:         "127.0.0.1",
				DBName:         "litecontrol",
				DBUser:         "lc_mail_ro",
				DBPassword:     "secret",
				DovecotSeries:  series,
				PasswordScheme: SchemeArgon2ID,
				RedisBinary:    "/usr/bin/valkey-server",
			})
			if err != nil {
				t.Fatalf("RenderAllConfigs failed: %v", err)
			}

			byPath := make(map[string]RenderedFile, len(files))
			for _, f := range files {
				byPath[f.RelPath] = f
			}

			// Postfix main.cf invariants
			mainCF := byPath["etc/postfix/main.cf"].Content
			for _, want := range []string{
				"myhostname = mail.example.com",
				"proxy:mysql:/etc/postfix/sql/domains.cf",
				"virtual_transport = lmtp:unix:private/dovecot-lmtp",
				"check_policy_service unix:private/quota-status",
				"smtpd_milters =\n",
				"non_smtpd_milters = inet:127.0.0.1:11332",
				"tls_server_sni_maps = hash:/etc/postfix/vmail_ssl.map",
				"sender_canonical_maps = tcp:127.0.0.1:10001",
			} {
				if !strings.Contains(mainCF, want) {
					t.Errorf("main.cf missing %q", want)
				}
			}

			// Postfix SQL maps permissions
			for _, sqlFile := range []string{
				"etc/postfix/sql/domains.cf",
				"etc/postfix/sql/mailboxes.cf",
				"etc/postfix/sql/mailbox-self.cf",
				"etc/postfix/sql/aliases.cf",
				"etc/postfix/sql/sender-mailboxes.cf",
				"etc/postfix/sql/sender-aliases.cf",
			} {
				rf, ok := byPath[sqlFile]
				if !ok {
					t.Fatalf("missing rendered file %s", sqlFile)
				}
				if rf.Mode != 0o640 {
					t.Errorf("%s mode = %o, want 0640", sqlFile, rf.Mode)
				}
			}

			// Rspamd-Light invariants
			dkim := byPath["etc/rspamd/local.d/dkim_signing.conf"].Content
			if !strings.Contains(dkim, "sign_local = false;") || !strings.Contains(dkim, "sign_authenticated = true;") {
				t.Errorf("dkim_signing.conf violated sign_local/sign_authenticated invariants")
			}
			ctrl := byPath["etc/rspamd/local.d/worker-controller.inc"].Content
			if !strings.Contains(ctrl, "enabled = false;") {
				t.Errorf("worker-controller.inc should be disabled by default")
			}

			// Dedicated lc-rspamd-redis invariants
			redisConf := byPath["etc/lc-rspamd-redis/redis.conf"].Content
			if !strings.Contains(redisConf, "maxmemory 32mb") || !strings.Contains(redisConf, "volatile-ttl") {
				t.Errorf("lc-rspamd-redis redis.conf missing memory/eviction settings")
			}

			// Versioned Dovecot invariants
			core := byPath["etc/dovecot/conf.d/99-litecontrol.conf"].Content
			if !strings.Contains(core, "ssl = required") || !strings.Contains(core, "login_trusted_networks = 127.0.0.1") {
				t.Errorf("dovecot core missing ssl=required or login_trusted_networks")
			}
			if series == Dovecot23 {
				if _, ok := byPath["etc/dovecot/dovecot-sql.conf.ext"]; !ok {
					t.Errorf("Dovecot 2.3 missing dovecot-sql.conf.ext")
				}
				if !strings.Contains(core, "mail_location =") || !strings.Contains(core, "sieve_before =") {
					t.Errorf("Dovecot 2.3 core missing mail_location or sieve_before")
				}
			} else {
				if _, ok := byPath["etc/dovecot/conf.d/auth-sql.conf.ext"]; !ok {
					t.Errorf("Dovecot 2.4 missing conf.d/auth-sql.conf.ext")
				}
				if !strings.Contains(core, "mail_driver = maildir") || !strings.Contains(core, "auth_allow_cleartext = no") {
					t.Errorf("Dovecot 2.4 core missing mail_driver or auth_allow_cleartext")
				}
			}
		})
	}
}

func TestInstallCoreAndUninstallCore(t *testing.T) {
	rootDir := t.TempDir()
	// Seed /etc/os-release (Ubuntu 24.04) and SELinux enforcing file
	if err := WriteFileAtomic(filepath.Join(rootDir, "etc", "os-release"), []byte("ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(filepath.Join(rootDir, "sys", "fs", "selinux", "enforce"), []byte("1\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	runner := &fakeRunner{
		binaries: map[string]string{
			"redis-server": "/usr/bin/redis-server",
			"mariadb":      "/usr/bin/mariadb",
		},
		handlers: map[string]func(args ...string) (string, error){
			"postconf -h mail_version": func(...string) (string, error) { return "3.8.6\n", nil },
			"dovecot --version":        func(...string) (string, error) { return "2.3.21\n", nil },
			"doveadm pw -l":            func(...string) (string, error) { return "ARGON2ID SHA512-CRYPT\n", nil },
			"getent group vmail":       func(...string) (string, error) { return "", fmt.Errorf("missing") },
			"id -u vmail":              func(...string) (string, error) { return "", fmt.Errorf("missing") },
		},
	}

	reconciled := false
	res, err := InstallCore(context.Background(), InstallOptions{
		Hostname:   "mail.example.com",
		DBPassword: "ro-secret",
		RootDir:    rootDir,
		Runner:     runner,
		ReconcileFunc: func(_ context.Context, scope string) error {
			reconciled = (scope == "all")
			return nil
		},
	})
	if err != nil {
		t.Fatalf("InstallCore failed: %v", err)
	}
	if res.Status != "success" || res.DovecotSeries != Dovecot23 || res.PasswordScheme != SchemeArgon2ID {
		t.Fatalf("unexpected InstallResult: %+v", res)
	}
	if !reconciled {
		t.Fatalf("expected post-install reconcile to be called")
	}

	// Verify SELinux restorecon -RF and validation gates ran
	joinedCalls := strings.Join(runner.calls, "\n")
	for _, want := range []string{
		"CREATE USER IF NOT EXISTS 'lc_mail_ro'@'127.0.0.1' IDENTIFIED BY 'ro-secret'",
		"ALTER USER 'lc_mail_ro'@'127.0.0.1' IDENTIFIED BY 'ro-secret'",
		"GRANT SELECT ON `litecontrol`.`v_dovecot_users` TO 'lc_mail_ro'@'127.0.0.1'",
		"restorecon -RF /etc/postfix",
		"postfix check",
		"doveconf -n",
		"rspamadm configtest",
		"systemctl enable --now lc-rspamd-redis",
	} {
		if !strings.Contains(joinedCalls, want) {
			t.Errorf("expected command %q to run; calls:\n%s", want, joinedCalls)
		}
	}

	// Create a dummy mailbox file to test UninstallCore preserve vs purge
	mailboxMarker := filepath.Join(rootDir, "var", "vmail", "example.com", "info", "Maildir")
	if err := os.MkdirAll(mailboxMarker, 0o750); err != nil {
		t.Fatal(err)
	}

	// Uninstall without --purge-data must preserve /var/vmail
	if _, err := UninstallCore(context.Background(), UninstallOptions{
		PurgeData: false,
		RootDir:   rootDir,
		Runner:    runner,
	}); err != nil {
		t.Fatalf("UninstallCore (preserve) failed: %v", err)
	}
	if _, err := os.Stat(mailboxMarker); err != nil {
		t.Fatalf("expected /var/vmail to be preserved without --purge-data: %v", err)
	}

	// Uninstall with --purge-data must remove /var/vmail
	if _, err := UninstallCore(context.Background(), UninstallOptions{
		PurgeData: true,
		RootDir:   rootDir,
		Runner:    runner,
	}); err != nil {
		t.Fatalf("UninstallCore (purge) failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(rootDir, "var", "vmail")); !os.IsNotExist(err) {
		t.Fatalf("expected /var/vmail to be removed with --purge-data")
	}
}
