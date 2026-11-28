package mail

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestProvisionAcmeVhostRendersCatchAllPort80Config(t *testing.T) {
	tmpDir := t.TempDir()
	runner := &fakeRunner{}

	res, err := ProvisionAcmeVhost(context.Background(), ProvisionAcmeVhostOptions{
		RootDir: tmpDir,
		Runner:  runner,
	})
	if err != nil {
		t.Fatalf("ProvisionAcmeVhost failed: %v", err)
	}

	if res.Status != "provisioned" {
		t.Fatalf("expected status provisioned, got %q", res.Status)
	}
	if res.Webroot != AcmeChallengeWebroot {
		t.Fatalf("expected webroot %q, got %q", AcmeChallengeWebroot, res.Webroot)
	}

	challengeDir := filepath.Join(tmpDir, strings.TrimPrefix(AcmeChallengeWebroot, "/"), ".well-known", "acme-challenge")
	if info, statErr := os.Stat(challengeDir); statErr != nil || !info.IsDir() {
		t.Fatalf("expected ACME challenge dir %s to exist", challengeDir)
	}

	vhconfBytes, err := os.ReadFile(res.VhostConfigPath)
	if err != nil {
		t.Fatalf("read vhconf.conf: %v", err)
	}
	vhconf := string(vhconfBytes)
	if !strings.Contains(vhconf, "context /.well-known/acme-challenge/") {
		t.Fatalf("expected vhconf to expose /.well-known/acme-challenge/, got:\n%s", vhconf)
	}
	if !strings.Contains(vhconf, "deny                  *") {
		t.Fatalf("expected vhconf to deny non-ACME paths, got:\n%s", vhconf)
	}

	vhostDeclBytes, err := os.ReadFile(res.VhostDeclPath)
	if err != nil {
		t.Fatalf("read vhost.conf: %v", err)
	}
	vhostDecl := string(vhostDeclBytes)
	if !strings.Contains(vhostDecl, "address                 *:80") || !strings.Contains(vhostDecl, "map                     lc-acme-challenge *") {
		t.Fatalf("expected vhost.conf to bind catch-all *:80 listener, got:\n%s", vhostDecl)
	}
	if !strings.Contains(vhostDecl, "enableScript            0") {
		t.Fatalf("expected vhost.conf to disable scripts, got:\n%s", vhostDecl)
	}

	if len(runner.calls) != 1 || runner.calls[0] != "systemctl reload lsws" {
		t.Fatalf("expected single lsws reload call, got %v", runner.calls)
	}
}

func TestIssueStandaloneMailTlsProvisionsVhostAndRunsAcmeSh(t *testing.T) {
	tmpDir := t.TempDir()
	runner := &fakeRunner{}

	res, err := IssueStandaloneMailTls(context.Background(), IssueStandaloneMailTlsOptions{
		Domain:       "Tenant-Mail.Example.COM",
		Email:        "admin@tenant-mail.example.com",
		CertStoreDir: "/etc/letsencrypt/live",
		RootDir:      tmpDir,
		Runner:       runner,
	})
	if err != nil {
		t.Fatalf("IssueStandaloneMailTls failed: %v", err)
	}

	if res.Status != "issued" {
		t.Fatalf("expected status issued, got %q", res.Status)
	}
	if res.Domain != "mail.tenant-mail.example.com" {
		t.Fatalf("expected domain mail.tenant-mail.example.com, got %q", res.Domain)
	}
	if !strings.HasSuffix(filepath.ToSlash(res.CertPath), "/mail.tenant-mail.example.com/fullchain.pem") {
		t.Fatalf("unexpected cert path: %q", res.CertPath)
	}
	if !strings.HasSuffix(filepath.ToSlash(res.KeyPath), "/mail.tenant-mail.example.com/privkey.pem") {
		t.Fatalf("unexpected key path: %q", res.KeyPath)
	}
	if res.ExpiresAt <= 0 {
		t.Fatalf("expected positive expires_at, got %d", res.ExpiresAt)
	}

	foundIssue := false
	foundInstall := false
	for _, call := range runner.calls {
		if strings.HasPrefix(call, DefaultAcmeBin+" --issue -d mail.tenant-mail.example.com -w "+AcmeChallengeWebroot) {
			foundIssue = true
		}
		if strings.HasPrefix(call, DefaultAcmeBin+" --install-cert -d mail.tenant-mail.example.com --ecc") {
			foundInstall = true
		}
	}
	if !foundIssue {
		t.Fatalf("expected acme.sh --issue HTTP-01 call in runner calls, got %v", runner.calls)
	}
	if !foundInstall {
		t.Fatalf("expected acme.sh --install-cert call in runner calls, got %v", runner.calls)
	}
}

func TestIssueStandaloneMailTlsRejectsInvalidInputs(t *testing.T) {
	_, err := IssueStandaloneMailTls(context.Background(), IssueStandaloneMailTlsOptions{
		Domain: "not a domain",
		Email:  "admin@example.com",
		Runner: &fakeRunner{},
	})
	if err == nil {
		t.Fatalf("expected error for invalid domain")
	}

	_, err = IssueStandaloneMailTls(context.Background(), IssueStandaloneMailTlsOptions{
		Domain: "example.com",
		Email:  "invalid-email",
		Runner: &fakeRunner{},
	})
	if err == nil {
		t.Fatalf("expected error for invalid email")
	}
}
