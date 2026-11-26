package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// InstallOptions configures `lc-mail install-core`.
type InstallOptions struct {
	Hostname      string
	DBHost        string
	DBName        string
	DBUser        string
	DBPassword    string
	FallbackCert  string
	FallbackKey   string
	RootDir       string // Defaults to "/" on Linux; overridable in tests.
	SkipPackages  bool
	SkipReconcile bool
	Runner        CommandRunner
	ReconcileFunc func(ctx context.Context, scope string) error
}

// InstallResult summarizes the completed core installation.
type InstallResult struct {
	Status         string         `json:"status"`
	Hostname       string         `json:"hostname"`
	OS             string         `json:"os"`
	PostfixVersion string         `json:"postfix_version"`
	DovecotVersion string         `json:"dovecot_version"`
	DovecotSeries  DovecotSeries  `json:"dovecot_series"`
	PasswordScheme PasswordScheme `json:"password_scheme"`
	RedisBinary    string         `json:"redis_binary"`
}

var fqdnRe = regexp.MustCompile(`^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$`)

// ValidateHostname ensures the provided hostname is a valid FQDN.
func ValidateHostname(hostname string) error {
	h := strings.TrimSpace(hostname)
	if h == "" || len(h) > 253 || !fqdnRe.MatchString(h) {
		return fmt.Errorf("invalid mail hostname %q: must be a valid FQDN", hostname)
	}
	return nil
}

// InstallCore provisions the complete LiteControl mail stack (Postfix, Dovecot, Rspamd-Light,
// postsrsd, vmail 5000:5000, and dedicated lc-rspamd-redis.service).
func InstallCore(ctx context.Context, opts InstallOptions) (InstallResult, error) {
	if err := ValidateHostname(opts.Hostname); err != nil {
		return InstallResult{}, err
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	osInfo, err := DetectOS(rootDir)
	if err != nil {
		return InstallResult{}, err
	}

	if !opts.SkipPackages {
		if err := installMailPackages(ctx, osInfo, rootDir, runner); err != nil {
			return InstallResult{}, err
		}
	}

	caps, err := ProbeCapabilities(ctx, runner)
	if err != nil {
		return InstallResult{}, err
	}

	if err := ensureVmailUser(ctx, rootDir, runner); err != nil {
		return InstallResult{}, err
	}

	redisBin, err := detectRedisBinary(runner)
	if err != nil {
		return InstallResult{}, err
	}

	dbHost := opts.DBHost
	if dbHost == "" {
		dbHost = "127.0.0.1"
	}
	dbName := opts.DBName
	if dbName == "" {
		dbName = "litecontrol"
	}
	dbUser := opts.DBUser
	if dbUser == "" {
		dbUser = "lc_mail_ro"
	}

	if opts.DBPassword != "" {
		if err := ensureReadonlyDBUser(ctx, runner, dbHost, dbName, dbUser, opts.DBPassword); err != nil {
			return InstallResult{}, err
		}
	}

	tplData := TemplateData{
		Hostname:       opts.Hostname,
		DBHost:         dbHost,
		DBName:         dbName,
		DBUser:         dbUser,
		DBPassword:     opts.DBPassword,
		FallbackCert:   opts.FallbackCert,
		FallbackKey:    opts.FallbackKey,
		DovecotSeries:  caps.DovecotSeries,
		PasswordScheme: caps.PasswordScheme,
		RedisBinary:    redisBin,
		RspamdUser:     "_rspamd",
	}

	files, err := RenderAllConfigs(tplData)
	if err != nil {
		return InstallResult{}, err
	}

	for _, f := range files {
		dest := filepath.Join(rootDir, filepath.FromSlash(f.RelPath))
		if err := WriteFileAtomic(dest, []byte(f.Content), f.Mode); err != nil {
			return InstallResult{}, err
		}
	}

	// Ensure auxiliary directories and empty map files exist before validation.
	auxDirs := []string{
		"var/vmail",
		"var/vmail/sieve",
		"var/lib/rspamd/dkim",
		"etc/dovecot/conf.d/sni",
	}
	for _, d := range auxDirs {
		if err := os.MkdirAll(filepath.Join(rootDir, filepath.FromSlash(d)), 0o750); err != nil {
			return InstallResult{}, fmt.Errorf("mkdir %s: %w", d, err)
		}
	}

	sniMapPath := filepath.Join(rootDir, "etc", "postfix", "vmail_ssl.map")
	if _, err := os.Stat(sniMapPath); os.IsNotExist(err) {
		if err := WriteFileAtomic(sniMapPath, []byte("# Managed by LiteControl lc-mail\n"), 0o644); err != nil {
			return InstallResult{}, err
		}
	}

	selectorMapPath := filepath.Join(rootDir, "etc", "rspamd", "local.d", "lc_dkim_selectors.map")
	if _, err := os.Stat(selectorMapPath); os.IsNotExist(err) {
		if err := WriteFileAtomic(selectorMapPath, []byte("# Managed by LiteControl lc-mail\n"), 0o644); err != nil {
			return InstallResult{}, err
		}
	}

	if _, err := runner.Run(ctx, "postmap", "-F", sniMapPath); err != nil {
		return InstallResult{}, fmt.Errorf("compile vmail_ssl.map: %w", err)
	}

	// Apply SELinux contexts if enforcing.
	managedPaths := []string{
		"/etc/postfix",
		"/etc/dovecot",
		"/etc/rspamd",
		"/etc/lc-rspamd-redis",
		"/var/vmail",
		"/var/lib/rspamd/dkim",
	}
	if err := RestoreSELinuxContexts(ctx, rootDir, runner, managedPaths); err != nil {
		return InstallResult{}, err
	}

	// Pre-reload validation gates.
	if _, err := runner.Run(ctx, "postfix", "check"); err != nil {
		return InstallResult{}, fmt.Errorf("postfix check failed: %w", err)
	}
	if _, err := runner.Run(ctx, "doveconf", "-n"); err != nil {
		return InstallResult{}, fmt.Errorf("doveconf -n failed: %w", err)
	}
	if _, err := runner.Run(ctx, "rspamadm", "configtest"); err != nil {
		return InstallResult{}, fmt.Errorf("rspamadm configtest failed: %w", err)
	}

	// Enable and start services (dedicated lc-rspamd-redis first; never touch Laravel Redis).
	if _, err := runner.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return InstallResult{}, fmt.Errorf("systemctl daemon-reload: %w", err)
	}
	for _, svc := range []string{"lc-rspamd-redis", "postsrsd", "rspamd", "dovecot", "postfix"} {
		if _, err := runner.Run(ctx, "systemctl", "enable", "--now", svc); err != nil {
			return InstallResult{}, fmt.Errorf("enable service %s: %w", svc, err)
		}
	}

	// Trigger reconcile at end of install-core.
	if !opts.SkipReconcile {
		if opts.ReconcileFunc != nil {
			if err := opts.ReconcileFunc(ctx, "all"); err != nil {
				return InstallResult{}, fmt.Errorf("post-install reconcile: %w", err)
			}
		}
	}

	return InstallResult{
		Status:         "success",
		Hostname:       opts.Hostname,
		OS:             fmt.Sprintf("%s %s", osInfo.ID, osInfo.VersionID),
		PostfixVersion: caps.PostfixVersion,
		DovecotVersion: caps.DovecotVersion,
		DovecotSeries:  caps.DovecotSeries,
		PasswordScheme: caps.PasswordScheme,
		RedisBinary:    redisBin,
	}, nil
}

func installMailPackages(ctx context.Context, osInfo OSInfo, rootDir string, runner CommandRunner) error {
	switch osInfo.Family {
	case OSFamilyDebian:
		// Add official rspamd.com APT repository.
		keyringDir := filepath.Join(rootDir, "etc", "apt", "keyrings")
		if err := os.MkdirAll(keyringDir, 0o755); err != nil {
			return fmt.Errorf("mkdir apt keyrings: %w", err)
		}
		if _, err := runner.Run(ctx, "sh", "-c", "wget -O- https://rspamd.com/apt-stable/gpg.key | gpg --dearmor -o /etc/apt/keyrings/rspamd.gpg"); err != nil {
			return fmt.Errorf("install rspamd apt gpg key: %w", err)
		}
		codename := osInfo.Codename
		if codename == "" {
			codename = "stable"
		}
		repoLine := fmt.Sprintf("deb [signed-by=/etc/apt/keyrings/rspamd.gpg] https://rspamd.com/apt-stable/ %s main\n", codename)
		if err := WriteFileAtomic(filepath.Join(rootDir, "etc", "apt", "sources.list.d", "rspamd.list"), []byte(repoLine), 0o644); err != nil {
			return err
		}
		if _, err := runner.Run(ctx, "apt-get", "update"); err != nil {
			return fmt.Errorf("apt-get update: %w", err)
		}
		pkgs := []string{
			"install", "-y",
			"postfix", "postfix-mysql",
			"dovecot-core", "dovecot-imapd", "dovecot-pop3d", "dovecot-lmtpd", "dovecot-mysql", "dovecot-sieve", "dovecot-managesieved",
			"rspamd", "postsrsd",
		}
		if _, err := runner.Run(ctx, "apt-get", pkgs...); err != nil {
			return fmt.Errorf("apt-get install mail stack: %w", err)
		}
		return nil

	case OSFamilyRHEL:
		// Add official rspamd.com RPM repository.
		repoContent := fmt.Sprintf(`[rspamd]
name=Rspamd stable repository
baseurl=https://rspamd.com/rpm-stable/centos-%d/$basearch
enabled=1
gpgcheck=1
gpgkey=https://rspamd.com/rpm-stable/gpg.key
`, osInfo.MajorVer)
		if err := WriteFileAtomic(filepath.Join(rootDir, "etc", "yum.repos.d", "rspamd.repo"), []byte(repoContent), 0o644); err != nil {
			return err
		}
		pkgs := []string{
			"install", "-y",
			"postfix", "postfix-mysql",
			"dovecot", "dovecot-mysql", "dovecot-pigeonhole",
			"rspamd", "postsrsd",
		}
		if _, err := runner.Run(ctx, "dnf", pkgs...); err != nil {
			return fmt.Errorf("dnf install mail stack: %w", err)
		}
		return nil

	default:
		return fmt.Errorf("unsupported OS family: %s", osInfo.Family)
	}
}

func ensureVmailUser(ctx context.Context, rootDir string, runner CommandRunner) error {
	// Idempotently create vmail group (5000) and user (5000:5000).
	if _, err := runner.Run(ctx, "getent", "group", "vmail"); err != nil {
		if _, err := runner.Run(ctx, "groupadd", "-g", "5000", "vmail"); err != nil {
			return fmt.Errorf("create vmail group: %w", err)
		}
	}
	if _, err := runner.Run(ctx, "id", "-u", "vmail"); err != nil {
		if _, err := runner.Run(ctx, "useradd", "-g", "vmail", "-u", "5000", "-d", "/var/vmail", "-s", "/usr/sbin/nologin", "-M", "vmail"); err != nil {
			return fmt.Errorf("create vmail user: %w", err)
		}
	}
	vmailDir := filepath.Join(rootDir, "var", "vmail")
	if err := os.MkdirAll(vmailDir, 0o750); err != nil {
		return fmt.Errorf("mkdir /var/vmail: %w", err)
	}
	return nil
}

func detectRedisBinary(runner CommandRunner) (string, error) {
	for _, candidate := range []string{"redis-server", "valkey-server"} {
		if p, err := runner.LookPath(candidate); err == nil && p != "" {
			return p, nil
		}
	}
	return "", fmt.Errorf("neither redis-server nor valkey-server found in PATH")
}

var mailReadonlyViews = []string{
	"v_postfix_domains",
	"v_postfix_mailboxes",
	"v_postfix_aliases",
	"v_postfix_mailbox_self",
	"v_postfix_sender_mailboxes",
	"v_postfix_sender_aliases",
	"v_dovecot_users",
	"mail_domains",
	"mail_dkim_keys",
}

func ensureReadonlyDBUser(ctx context.Context, runner CommandRunner, dbHost, dbName, dbUser, dbPassword string) error {
	dbBin := ""
	for _, candidate := range []string{"mariadb", "mysql"} {
		if p, err := runner.LookPath(candidate); err == nil && p != "" {
			dbBin = p
			break
		}
	}
	if dbBin == "" {
		return fmt.Errorf("neither mariadb nor mysql client found in PATH")
	}

	hosts := []string{dbHost}
	if dbHost == "127.0.0.1" {
		hosts = append(hosts, "localhost")
	} else if dbHost == "localhost" {
		hosts = append(hosts, "127.0.0.1")
	}

	escUser := escapeSQLString(dbUser)
	escPass := escapeSQLString(dbPassword)
	escDB := strings.ReplaceAll(dbName, "`", "``")

	var b strings.Builder
	for _, h := range hosts {
		escHost := escapeSQLString(h)
		fmt.Fprintf(&b, "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s'; ", escUser, escHost, escPass)
		fmt.Fprintf(&b, "ALTER USER '%s'@'%s' IDENTIFIED BY '%s'; ", escUser, escHost, escPass)
		for _, view := range mailReadonlyViews {
			fmt.Fprintf(&b, "GRANT SELECT ON `%s`.`%s` TO '%s'@'%s'; ", escDB, view, escUser, escHost)
		}
	}
	b.WriteString("FLUSH PRIVILEGES;")

	if _, err := runner.Run(ctx, dbBin, "-u", "root", "-e", b.String()); err != nil {
		return fmt.Errorf("provision read-only mail DB user %s@%s: %w", dbUser, dbHost, err)
	}
	return nil
}

func escapeSQLString(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, `'`, `\'`)
	return s
}
