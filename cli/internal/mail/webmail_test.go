package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type webmailStubRunner struct {
	calls    []string
	userMade bool
	lswsUp   bool
}

func (r *webmailStubRunner) Run(_ context.Context, name string, args ...string) (string, error) {
	cmd := name + " " + strings.Join(args, " ")
	r.calls = append(r.calls, cmd)

	if name == "id" && len(args) >= 2 && args[0] == "-u" && args[1] == WebmailSystemUser {
		if !r.userMade {
			return "", fmt.Errorf("no such user")
		}
		return "998\n", nil
	}
	if name == "useradd" {
		r.userMade = true
		return "", nil
	}
	if name == "systemctl" && len(args) >= 2 && args[0] == "is-active" && args[1] == "lsws" {
		if r.lswsUp {
			return "active\n", nil
		}
		return "inactive\n", fmt.Errorf("inactive")
	}
	return "", nil
}

func (r *webmailStubRunner) LookPath(file string) (string, error) {
	return "/usr/bin/" + file, nil
}

func TestWebmailInstallAndUninstall(t *testing.T) {
	root := t.TempDir()
	runner := &webmailStubRunner{lswsUp: true}

	res, err := WebmailInstall(context.Background(), WebmailInstallOptions{
		Driver:   "snappymail",
		Hostname: "mail.example.com",
		RootDir:  root,
		Runner:   runner,
	})
	if err != nil {
		t.Fatalf("WebmailInstall failed: %v", err)
	}

	if res.Status != "installed" || res.Driver != "snappymail" || res.Hostname != "mail.example.com" {
		t.Fatalf("unexpected install result: %+v", res)
	}
	if res.SystemUser != WebmailSystemUser {
		t.Fatalf("expected isolated user %q, got %q", WebmailSystemUser, res.SystemUser)
	}

	// Verify OLS vhost config enforces isolated UID/GID and dedicated LSAPI socket.
	vhconfBytes, err := os.ReadFile(res.VhostConfigPath)
	if err != nil {
		t.Fatalf("read vhconf.conf: %v", err)
	}
	vhconf := string(vhconfBytes)
	for _, want := range []string{
		"extUser                 lc-webmail",
		"extGroup                lc-webmail",
		"uds://var/lib/lc-webmail/run/lsphp.sock",
		"vhDomain                  mail.example.com",
	} {
		if !strings.Contains(vhconf, want) {
			t.Errorf("vhconf.conf missing %q", want)
		}
	}

	// Verify SnappyMail config uses loopback TLS + IMAP ID real-IP forwarding.
	appIniBytes, err := os.ReadFile(filepath.Join(root, "var/lib/lc-webmail/data/_data_/_default_/configs/application.ini"))
	if err != nil {
		t.Fatalf("read application.ini: %v", err)
	}
	if !strings.Contains(string(appIniBytes), "imap_id_client_ip = On") {
		t.Errorf("application.ini missing imap_id_client_ip = On")
	}

	domainCfgBytes, err := os.ReadFile(filepath.Join(root, "var/lib/lc-webmail/data/_data_/_default_/domains/default.json"))
	if err != nil {
		t.Fatalf("read default.json: %v", err)
	}
	if !strings.Contains(string(domainCfgBytes), `"host": "127.0.0.1"`) || !strings.Contains(string(domainCfgBytes), `"port": 993`) {
		t.Errorf("default.json missing loopback TLS IMAP config")
	}

	// Verify WebmailStatus reports installed + running.
	st, err := WebmailStatus(context.Background(), WebmailStatusOptions{
		Driver:  "snappymail",
		RootDir: root,
		Runner:  runner,
	})
	if err != nil {
		t.Fatalf("WebmailStatus failed: %v", err)
	}
	if !st.Installed || !st.Running || st.Hostname != "mail.example.com" {
		t.Fatalf("unexpected status: %+v", st)
	}

	// Verify WebmailUninstall cleans up OLS vhost and app directory.
	unres, err := WebmailUninstall(context.Background(), WebmailUninstallOptions{
		Driver:  "snappymail",
		RootDir: root,
		Runner:  runner,
	})
	if err != nil {
		t.Fatalf("WebmailUninstall failed: %v", err)
	}
	if !unres.VhostRemoved || !unres.AppRemoved {
		t.Fatalf("expected vhost and app removed, got %+v", unres)
	}

	if _, err := os.Stat(filepath.Join(root, "var/lib/lc-webmail")); !os.IsNotExist(err) {
		t.Errorf("expected /var/lib/lc-webmail to be removed")
	}
	if _, err := os.Stat(filepath.Join(root, "usr/local/lsws/conf/vhosts/lc-webmail")); !os.IsNotExist(err) {
		t.Errorf("expected OLS vhost dir to be removed")
	}
}

func TestWebmailInstallValidation(t *testing.T) {
	if _, err := WebmailInstall(context.Background(), WebmailInstallOptions{
		Driver:   "roundcube",
		Hostname: "mail.example.com",
		RootDir:  t.TempDir(),
	}); err == nil {
		t.Fatal("expected error for unsupported webmail driver")
	}

	if _, err := WebmailInstall(context.Background(), WebmailInstallOptions{
		Driver:   "snappymail",
		Hostname: "../bad-host",
		RootDir:  t.TempDir(),
	}); err == nil {
		t.Fatal("expected error for invalid hostname")
	}
}
