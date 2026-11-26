package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const (
	// AcmeChallengeWebroot is the shared static webroot used by the catch-all port-80
	// OpenLiteSpeed vhost to serve HTTP-01 ACME challenges for standalone mail.{domain} hosts.
	AcmeChallengeWebroot = "/var/www/lc-acme-challenge"

	// AcmeOlsVhostDir is the dedicated OpenLiteSpeed vhost directory for HTTP-01 ACME challenges.
	AcmeOlsVhostDir = "/usr/local/lsws/conf/vhosts/lc-acme-challenge"

	// DefaultCertStoreDir is the standard Let's Encrypt live certificate directory.
	DefaultCertStoreDir = "/etc/letsencrypt/live"
)

// ProvisionAcmeVhostOptions configures `lc-mail acme-vhost-provision`.
type ProvisionAcmeVhostOptions struct {
	RootDir    string // Defaults to "/" on Linux; overridable in tests.
	SkipReload bool
	Runner     CommandRunner
}

// ProvisionAcmeVhostResult is emitted as JSON by `lc-mail acme-vhost-provision`.
type ProvisionAcmeVhostResult struct {
	Status          string `json:"status"`
	Webroot         string `json:"webroot"`
	VhostConfigPath string `json:"vhost_config_path"`
	VhostDeclPath   string `json:"vhost_decl_path"`
}

// IssueStandaloneMailTlsOptions configures `lc-mail tls-issue`.
type IssueStandaloneMailTlsOptions struct {
	Domain       string // Base mail domain, e.g. example.com (cert issued for mail.example.com).
	Email        string // Contact email for ACME registration.
	CertStoreDir string // Optional override for /etc/letsencrypt/live.
	RootDir      string // Defaults to "/" on Linux; overridable in tests.
	SkipReload   bool
	Runner       CommandRunner
}

// IssueStandaloneMailTlsResult is emitted as JSON by `lc-mail tls-issue`.
type IssueStandaloneMailTlsResult struct {
	Status    string `json:"status"`
	Domain    string `json:"domain"`
	CertPath  string `json:"cert_path"`
	KeyPath   string `json:"key_path"`
	ExpiresAt int64  `json:"expires_at"`
}

// ProvisionAcmeVhost idempotently provisions a catch-all port-80 OpenLiteSpeed vhost
// serving only /.well-known/acme-challenge/ for HTTP-01 TLS issuance on standalone mail.{domain} hosts.
func ProvisionAcmeVhost(ctx context.Context, opts ProvisionAcmeVhostOptions) (ProvisionAcmeVhostResult, error) {
	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	webroot := filepath.Join(rootDir, strings.TrimPrefix(AcmeChallengeWebroot, "/"))
	challengeDir := filepath.Join(webroot, ".well-known", "acme-challenge")
	if err := os.MkdirAll(challengeDir, 0o755); err != nil {
		return ProvisionAcmeVhostResult{}, fmt.Errorf("create ACME challenge dir %s: %w", challengeDir, err)
	}

	olsVhostDir := filepath.Join(rootDir, strings.TrimPrefix(AcmeOlsVhostDir, "/"))
	if err := os.MkdirAll(olsVhostDir, 0o755); err != nil {
		return ProvisionAcmeVhostResult{}, fmt.Errorf("create OLS ACME vhost dir %s: %w", olsVhostDir, err)
	}

	vhconfPath := filepath.Join(olsVhostDir, "vhconf.conf")
	vhconf := renderAcmeOlsVhconf()
	if err := os.WriteFile(vhconfPath, []byte(vhconf), 0o644); err != nil {
		return ProvisionAcmeVhostResult{}, fmt.Errorf("write OLS ACME vhconf.conf: %w", err)
	}

	vhostDeclPath := filepath.Join(olsVhostDir, "vhost.conf")
	vhostDecl := renderAcmeOlsVhostDecl()
	if err := os.WriteFile(vhostDeclPath, []byte(vhostDecl), 0o644); err != nil {
		return ProvisionAcmeVhostResult{}, fmt.Errorf("write OLS ACME vhost.conf: %w", err)
	}

	if !opts.SkipReload {
		if err := reloadOpenLiteSpeed(ctx, runner); err != nil {
			return ProvisionAcmeVhostResult{}, fmt.Errorf("reload OpenLiteSpeed: %w", err)
		}
	}

	return ProvisionAcmeVhostResult{
		Status:          "provisioned",
		Webroot:         AcmeChallengeWebroot,
		VhostConfigPath: vhconfPath,
		VhostDeclPath:   vhostDeclPath,
	}, nil
}

// IssueStandaloneMailTls ensures the catch-all port-80 OpenLiteSpeed ACME challenge vhost
// is provisioned and obtains an HTTP-01 TLS certificate for mail.{domain}.
func IssueStandaloneMailTls(ctx context.Context, opts IssueStandaloneMailTlsOptions) (IssueStandaloneMailTlsResult, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if !validHostnameRe.MatchString(domain) {
		return IssueStandaloneMailTlsResult{}, fmt.Errorf("invalid mail domain %q (must be a valid FQDN)", opts.Domain)
	}

	email := strings.TrimSpace(opts.Email)
	if email == "" || !strings.Contains(email, "@") {
		return IssueStandaloneMailTlsResult{}, fmt.Errorf("invalid ACME contact email %q", opts.Email)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	if _, err := ProvisionAcmeVhost(ctx, ProvisionAcmeVhostOptions{
		RootDir:    opts.RootDir,
		SkipReload: opts.SkipReload,
		Runner:     runner,
	}); err != nil {
		return IssueStandaloneMailTlsResult{}, fmt.Errorf("provision ACME challenge vhost: %w", err)
	}

	mailHost := "mail." + domain
	if _, err := runner.Run(
		ctx,
		"certbot",
		"certonly",
		"--webroot",
		"-w", AcmeChallengeWebroot,
		"-d", mailHost,
		"--non-interactive",
		"--agree-tos",
		"--email", email,
		"--keep-until-expiring",
	); err != nil {
		return IssueStandaloneMailTlsResult{}, fmt.Errorf("certbot HTTP-01 issuance for %s failed: %w", mailHost, err)
	}

	certStore := strings.TrimSpace(opts.CertStoreDir)
	if certStore == "" {
		certStore = DefaultCertStoreDir
	}

	certPath := filepath.Join(certStore, mailHost, "fullchain.pem")
	keyPath := filepath.Join(certStore, mailHost, "privkey.pem")
	expiresAt := time.Now().Add(90 * 24 * time.Hour).Unix()

	return IssueStandaloneMailTlsResult{
		Status:    "issued",
		Domain:    mailHost,
		CertPath:  certPath,
		KeyPath:   keyPath,
		ExpiresAt: expiresAt,
	}, nil
}

func renderAcmeOlsVhconf() string {
	return fmt.Sprintf(`# Managed by LiteControl (lc-mail acme-vhost-provision)
# Catch-all port-80 ACME HTTP-01 challenge vhost for standalone mail.{domain} hosts.
docRoot                   %s
vhDomain                  *
enableGzip                0

context /.well-known/acme-challenge/ {
  location                %s/.well-known/acme-challenge/
  allowBrowse             1
  addDefaultCharset       off
}

context / {
  location                %s
  allowBrowse             0
  accessControl {
    deny                  *
  }
}
`, AcmeChallengeWebroot, AcmeChallengeWebroot, AcmeChallengeWebroot)
}

func renderAcmeOlsVhostDecl() string {
	return fmt.Sprintf(`# Managed by LiteControl (lc-mail acme-vhost-provision)
# Catch-all port-80 OpenLiteSpeed listener mapping for standalone mail.{domain} ACME HTTP-01 challenges.
virtualhost lc-acme-challenge {
  vhRoot                  %s
  configFile              %s/vhconf.conf
  allowSymbolLink         0
  enableScript            0
  restrained              1
}

listener Default {
  address                 *:80
  secure                  0
  map                     lc-acme-challenge *
}
`, AcmeChallengeWebroot, AcmeOlsVhostDir)
}
