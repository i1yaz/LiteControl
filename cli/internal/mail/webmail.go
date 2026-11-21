package mail

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

const (
	// WebmailSystemUser is the isolated UID/GID used exclusively by SnappyMail's LSAPI pool.
	// It has zero access to LiteControl's panel PHP-FPM pool or database.
	WebmailSystemUser  = "lc-webmail"
	WebmailSystemGroup = "lc-webmail"

	// WebmailBaseDir is the isolated root directory for SnappyMail app, data, and LSAPI socket.
	WebmailBaseDir = "/var/lib/lc-webmail"

	// WebmailOlsVhostDir is the dedicated OpenLiteSpeed vhost directory for webmail.
	WebmailOlsVhostDir = "/usr/local/lsws/conf/vhosts/lc-webmail"

	// WebmailStateRelPath stores installed webmail metadata under /var/lib/lc-mail.
	WebmailStateRelPath = "var/lib/lc-mail/webmail.json"
)

var validHostnameRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// WebmailInstallOptions configures `lc-mail webmail-install`.
type WebmailInstallOptions struct {
	Driver       string // snappymail (default)
	Hostname     string // Server-level FQDN hostname (required)
	LsphpBin     string // Optional path to lsphp binary (defaults to /usr/local/lsws/lsphp83/bin/lsphp)
	RootDir      string // Defaults to "/" on Linux; overridable in tests.
	SkipReload   bool
	Runner       CommandRunner
}

// WebmailInstallResult is emitted as JSON by `lc-mail webmail-install`.
type WebmailInstallResult struct {
	Status          string `json:"status"`
	Driver          string `json:"driver"`
	Hostname        string `json:"hostname"`
	SystemUser      string `json:"system_user"`
	VhostConfigPath string `json:"vhost_config_path"`
	AppDir          string `json:"app_dir"`
	LsapiSocket     string `json:"lsapi_socket"`
	ImapHost        string `json:"imap_host"`
	SmtpHost        string `json:"smtp_host"`
}

// WebmailUninstallOptions configures `lc-mail webmail-uninstall`.
type WebmailUninstallOptions struct {
	Driver     string // snappymail (default)
	RootDir    string // Defaults to "/" on Linux; overridable in tests.
	SkipReload bool
	Runner     CommandRunner
}

// WebmailUninstallResult is emitted as JSON by `lc-mail webmail-uninstall`.
type WebmailUninstallResult struct {
	Status       string `json:"status"`
	Driver       string `json:"driver"`
	VhostRemoved bool   `json:"vhost_removed"`
	AppRemoved   bool   `json:"app_removed"`
}

// WebmailStatusOptions configures `lc-mail webmail-status`.
type WebmailStatusOptions struct {
	Driver  string
	RootDir string
	Runner  CommandRunner
}

// WebmailStatusResult is emitted as JSON by `lc-mail webmail-status`.
type WebmailStatusResult struct {
	Driver    string `json:"driver"`
	Hostname  string `json:"hostname"`
	Installed bool   `json:"installed"`
	Running   bool   `json:"running"`
}

type webmailState struct {
	Driver   string `json:"driver"`
	Hostname string `json:"hostname"`
}

// WebmailInstall provisions a dedicated OpenLiteSpeed vhost and isolated LSAPI pool
// under the `lc-webmail` UID on the server-level hostname, wired to loopback TLS
// IMAP/SMTP with IMAP ID real-IP forwarding.
func WebmailInstall(ctx context.Context, opts WebmailInstallOptions) (WebmailInstallResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "snappymail"
	}
	if driver != "snappymail" {
		return WebmailInstallResult{}, fmt.Errorf("unsupported webmail driver %q (expected snappymail)", opts.Driver)
	}

	hostname := strings.ToLower(strings.TrimSpace(opts.Hostname))
	if !validHostnameRe.MatchString(hostname) {
		return WebmailInstallResult{}, fmt.Errorf("invalid webmail hostname %q (must be a valid FQDN)", opts.Hostname)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	lsphpBin := strings.TrimSpace(opts.LsphpBin)
	if lsphpBin == "" {
		lsphpBin = "/usr/local/lsws/lsphp83/bin/lsphp"
	}

	// 1. Ensure isolated lc-webmail system user exists (zero access to panel PHP-FPM or DB).
	if _, err := runner.Run(ctx, "id", "-u", WebmailSystemUser); err != nil {
		if _, addErr := runner.Run(
			ctx,
			"useradd",
			"--system",
			"--no-create-home",
			"--shell", "/usr/sbin/nologin",
			"--home-dir", WebmailBaseDir,
			WebmailSystemUser,
		); addErr != nil {
			return WebmailInstallResult{}, fmt.Errorf("create %s system user: %w", WebmailSystemUser, addErr)
		}
	}

	// 2. Create isolated directory layout under /var/lib/lc-webmail.
	baseDir := filepath.Join(rootDir, strings.TrimPrefix(WebmailBaseDir, "/"))
	appDir := filepath.Join(baseDir, "app")
	dataDir := filepath.Join(baseDir, "data")
	runDir := filepath.Join(baseDir, "run")
	logsDir := filepath.Join(baseDir, "logs")
	configDir := filepath.Join(dataDir, "_data_", "_default_", "configs")
	domainsDir := filepath.Join(dataDir, "_data_", "_default_", "domains")

	for _, dir := range []string{appDir, dataDir, runDir, logsDir, configDir, domainsDir} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return WebmailInstallResult{}, fmt.Errorf("create webmail directory %s: %w", dir, err)
		}
	}

	// 3. Render SnappyMail configuration: loopback TLS IMAP/SMTP + IMAP ID real-IP forwarding.
	appIni := renderSnappyMailApplicationIni()
	if err := os.WriteFile(filepath.Join(configDir, "application.ini"), []byte(appIni), 0o640); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("write SnappyMail application.ini: %w", err)
	}

	domainCfg := renderSnappyMailDefaultDomainConfig()
	if err := os.WriteFile(filepath.Join(domainsDir, "default.json"), []byte(domainCfg), 0o640); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("write SnappyMail default domain config: %w", err)
	}

	// 4. Render dedicated OpenLiteSpeed vhost and isolated LSAPI pool.
	olsVhostDir := filepath.Join(rootDir, strings.TrimPrefix(WebmailOlsVhostDir, "/"))
	if err := os.MkdirAll(olsVhostDir, 0o755); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("create OLS vhost dir %s: %w", olsVhostDir, err)
	}

	lsapiSocket := "/var/lib/lc-webmail/run/lsphp.sock"
	vhconfPath := filepath.Join(olsVhostDir, "vhconf.conf")
	vhconf := renderWebmailOlsVhconf(hostname, lsphpBin, lsapiSocket)
	if err := os.WriteFile(vhconfPath, []byte(vhconf), 0o644); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("write OLS vhconf.conf: %w", err)
	}

	vhostDeclPath := filepath.Join(olsVhostDir, "vhost.conf")
	vhostDecl := renderWebmailOlsVhostDecl(hostname)
	if err := os.WriteFile(vhostDeclPath, []byte(vhostDecl), 0o644); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("write OLS vhost.conf: %w", err)
	}

	// 5. Persist webmail state metadata.
	statePath := filepath.Join(rootDir, WebmailStateRelPath)
	if err := os.MkdirAll(filepath.Dir(statePath), 0o755); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("create webmail state dir: %w", err)
	}
	stateBytes, err := json.MarshalIndent(webmailState{
		Driver:   driver,
		Hostname: hostname,
	}, "", "  ")
	if err != nil {
		return WebmailInstallResult{}, fmt.Errorf("encode webmail state: %w", err)
	}
	if err := os.WriteFile(statePath, stateBytes, 0o644); err != nil {
		return WebmailInstallResult{}, fmt.Errorf("write webmail state: %w", err)
	}

	// 6. Reload OpenLiteSpeed so the new isolated vhost is live.
	if !opts.SkipReload {
		if _, err := runner.Run(ctx, "systemctl", "reload", "lsws"); err != nil {
			return WebmailInstallResult{}, fmt.Errorf("reload OpenLiteSpeed: %w", err)
		}
	}

	return WebmailInstallResult{
		Status:          "installed",
		Driver:          driver,
		Hostname:        hostname,
		SystemUser:      WebmailSystemUser,
		VhostConfigPath: vhconfPath,
		AppDir:          appDir,
		LsapiSocket:     lsapiSocket,
		ImapHost:        "127.0.0.1:993",
		SmtpHost:        "127.0.0.1:465",
	}, nil
}

// WebmailUninstall cleanly removes the dedicated OpenLiteSpeed vhost and app directory.
func WebmailUninstall(ctx context.Context, opts WebmailUninstallOptions) (WebmailUninstallResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "snappymail"
	}
	if driver != "snappymail" {
		return WebmailUninstallResult{}, fmt.Errorf("unsupported webmail driver %q (expected snappymail)", opts.Driver)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	olsVhostDir := filepath.Join(rootDir, strings.TrimPrefix(WebmailOlsVhostDir, "/"))
	vhostRemoved := false
	if _, err := os.Stat(olsVhostDir); err == nil {
		if err := os.RemoveAll(olsVhostDir); err != nil {
			return WebmailUninstallResult{}, fmt.Errorf("remove OLS vhost dir: %w", err)
		}
		vhostRemoved = true
	}

	baseDir := filepath.Join(rootDir, strings.TrimPrefix(WebmailBaseDir, "/"))
	appRemoved := false
	if _, err := os.Stat(baseDir); err == nil {
		if err := os.RemoveAll(baseDir); err != nil {
			return WebmailUninstallResult{}, fmt.Errorf("remove webmail app dir: %w", err)
		}
		appRemoved = true
	}

	statePath := filepath.Join(rootDir, WebmailStateRelPath)
	_ = os.Remove(statePath)

	if !opts.SkipReload {
		if _, err := runner.Run(ctx, "systemctl", "reload", "lsws"); err != nil {
			return WebmailUninstallResult{}, fmt.Errorf("reload OpenLiteSpeed: %w", err)
		}
	}

	return WebmailUninstallResult{
		Status:       "uninstalled",
		Driver:       driver,
		VhostRemoved: vhostRemoved,
		AppRemoved:   appRemoved,
	}, nil
}

// WebmailStatus reports whether the configured webmail driver is installed and running.
func WebmailStatus(ctx context.Context, opts WebmailStatusOptions) (WebmailStatusResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "snappymail"
	}
	if driver != "snappymail" {
		return WebmailStatusResult{}, fmt.Errorf("unsupported webmail driver %q (expected snappymail)", opts.Driver)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	statePath := filepath.Join(rootDir, WebmailStateRelPath)
	raw, err := os.ReadFile(statePath)
	if err != nil {
		return WebmailStatusResult{
			Driver:    driver,
			Installed: false,
			Running:   false,
		}, nil
	}

	var st webmailState
	if err := json.Unmarshal(raw, &st); err != nil {
		return WebmailStatusResult{
			Driver:    driver,
			Installed: false,
			Running:   false,
		}, nil
	}

	vhconfPath := filepath.Join(rootDir, strings.TrimPrefix(WebmailOlsVhostDir, "/"), "vhconf.conf")
	if _, err := os.Stat(vhconfPath); err != nil {
		return WebmailStatusResult{
			Driver:    driver,
			Hostname:  st.Hostname,
			Installed: false,
			Running:   false,
		}, nil
	}

	running := false
	if out, err := runner.Run(ctx, "systemctl", "is-active", "lsws"); err == nil {
		running = strings.TrimSpace(out) == "active"
	}

	return WebmailStatusResult{
		Driver:    driver,
		Hostname:  st.Hostname,
		Installed: true,
		Running:   running,
	}, nil
}

func renderSnappyMailApplicationIni() string {
	return `; Managed by LiteControl (lc-mail webmail-install)
[webmail]
title = "LiteControl Webmail"
allow_additional_accounts = Off

[imap]
; Forward real client IP via IMAP ID extension so Dovecot (login_trusted_networks = 127.0.0.1)
; logs the client IP for Fail2ban and audit trails.
use_imap_id_extension = On
imap_id_client_ip = On

[security]
allow_admin_panel = Off
custom_server_signature = "LiteControl"
`
}

func renderSnappyMailDefaultDomainConfig() string {
	return `{
  "IMAP": {
    "host": "127.0.0.1",
    "port": 993,
    "type": "ssl",
    "shortLogin": false,
    "lowerLogin": true,
    "sasl": ["PLAIN", "LOGIN"],
    "ssl": {
      "verify_peer": false,
      "verify_peer_name": false,
      "allow_self_signed": true
    }
  },
  "SMTP": {
    "host": "127.0.0.1",
    "port": 465,
    "type": "ssl",
    "shortLogin": false,
    "lowerLogin": true,
    "useAuth": true,
    "setSender": true,
    "ssl": {
      "verify_peer": false,
      "verify_peer_name": false,
      "allow_self_signed": true
    }
  }
}
`
}

func renderWebmailOlsVhconf(hostname, lsphpBin, lsapiSocket string) string {
	return fmt.Sprintf(`# Managed by LiteControl (lc-mail webmail-install)
# Isolated SnappyMail vhost running under UID/GID %s
docRoot                   %s/app
vhDomain                  %s
enableGzip                1

errorlog %s/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog %s/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                14
}

index {
  useServer               0
  indexFiles              index.php
}

extprocessor lc-webmail-lsphp {
  type                    lsapi
  address                 uds:/%s
  maxConns                20
  env                     PHP_LSAPI_CHILDREN=20
  env                     LSAPI_AVOID_FORK=200M
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               1
  path                    %s
  backlog                 100
  instances               1
  extUser                 %s
  extGroup                %s
  priority                0
  memSoftLimit            256M
  memHardLimit            512M
  procSoftLimit           50
  procHardLimit           100
}

scripthandler {
  add                     lsapi:lc-webmail-lsphp php
}

context /data {
  location                %s/data
  allowBrowse             0
}

vhssl {
  keyFile                 /etc/letsencrypt/live/%s/privkey.pem
  certFile                /etc/letsencrypt/live/%s/fullchain.pem
  certChain               1
}
`, WebmailSystemUser, WebmailBaseDir, hostname, WebmailBaseDir, WebmailBaseDir, lsapiSocket, lsphpBin, WebmailSystemUser, WebmailSystemGroup, WebmailBaseDir, hostname, hostname)
}

func renderWebmailOlsVhostDecl(hostname string) string {
	return fmt.Sprintf(`# Managed by LiteControl (lc-mail webmail-install)
virtualhost lc-webmail {
  vhRoot                  %s
  configFile              %s/vhconf.conf
  allowSymbolLink         0
  enableScript            1
  restrained              1
  setUIDMode              2
}
# Listener mapping: map lc-webmail %s
`, WebmailBaseDir, WebmailOlsVhostDir, hostname)
}
