package mail

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// MailDomainState represents an active mail domain row read from MariaDB.
type MailDomainState struct {
	Domain              string
	Active              bool
	DkimEnabled         bool
	SpamFilterEnabled   bool
	SpamHeaderScore     float64
	SpamRejectScore     float64
	OutboundHourlyLimit int
}

// DkimKeyState represents an active or retired DKIM key row read from MariaDB.
type DkimKeyState struct {
	Domain         string
	Selector       string
	Algorithm      string
	PrivateKeyPath string
	KeySHA256      string
	Status         string // pending, active, rotating, retired
}

// CertPair holds resolved TLS certificate and private key paths for a domain.
type CertPair struct {
	CertPath string
	KeyPath  string
}

// ReconcileState is the authoritative snapshot loaded before staging configs.
type ReconcileState struct {
	Domains     []MailDomainState
	DkimKeys    []DkimKeyState
	Certs       map[string]CertPair // keyed by domain
	FilterMode  string              // "light" (default) or "full"
	PostfixMilt string              // optional explicit smtpd_milters override
}

// ReconcileWarning is a structured warning emitted during reconcile (e.g. DKIM key drift).
type ReconcileWarning struct {
	Code     string `json:"code"`
	Domain   string `json:"domain,omitempty"`
	Selector string `json:"selector,omitempty"`
	Message  string `json:"message"`
}

// StateReader loads active mail state from MariaDB and the certificate store.
type StateReader interface {
	ReadState(ctx context.Context) (ReconcileState, error)
}

// MariaDBStateReader queries MariaDB via the mysql/mariadb CLI using lc_mail_ro
// and discovers TLS certificates from LiteControl's cert store.
type MariaDBStateReader struct {
	DBHost       string
	DBName       string
	DBUser       string
	DBPassword   string
	CertStoreDir string // Defaults to /etc/letsencrypt/live
	RootDir      string
	Runner       CommandRunner
}

// ReadState reads active mail_domains, mail_dkim_keys, and resolves SSL cert paths.
func (r MariaDBStateReader) ReadState(ctx context.Context) (ReconcileState, error) {
	runner := r.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	dbHost := r.DBHost
	if dbHost == "" {
		dbHost = "127.0.0.1"
	}
	dbName := r.DBName
	if dbName == "" {
		dbName = "litecontrol"
	}
	dbUser := r.DBUser
	if dbUser == "" {
		dbUser = "lc_mail_ro"
	}

	mysqlBin := "mariadb"
	if _, err := runner.LookPath(mysqlBin); err != nil {
		mysqlBin = "mysql"
	}

	var defaultsFile string
	if r.DBPassword != "" {
		f, err := os.CreateTemp("", ".lc-mail-mycnf-*")
		if err != nil {
			return ReconcileState{}, fmt.Errorf("create temp mariadb defaults file: %w", err)
		}
		defaultsFile = f.Name()
		defer os.Remove(defaultsFile)

		escapedPwd := strings.ReplaceAll(r.DBPassword, `\`, `\\`)
		escapedPwd = strings.ReplaceAll(escapedPwd, `"`, `\"`)
		cnfContent := fmt.Sprintf("[client]\npassword=\"%s\"\n", escapedPwd)
		if err := f.Chmod(0o600); err != nil {
			_ = f.Close()
			return ReconcileState{}, fmt.Errorf("chmod temp mariadb defaults file: %w", err)
		}
		if _, err := f.WriteString(cnfContent); err != nil {
			_ = f.Close()
			return ReconcileState{}, fmt.Errorf("write temp mariadb defaults file: %w", err)
		}
		if err := f.Close(); err != nil {
			return ReconcileState{}, fmt.Errorf("close temp mariadb defaults file: %w", err)
		}
	}

	runQuery := func(sql string) (string, error) {
		var args []string
		if defaultsFile != "" {
			// Use --defaults-file (not --defaults-extra-file) so /root/.my.cnf cannot override the password.
			args = append(args, "--defaults-file="+defaultsFile)
		}
		args = append(args,
			"--batch", "--raw", "--skip-column-names",
			"-h", dbHost,
			"-u", dbUser,
			dbName, "-e", sql,
		)
		return runner.Run(ctx, mysqlBin, args...)
	}

	state := ReconcileState{
		Certs:      make(map[string]CertPair),
		FilterMode: "light",
	}

	// 1. Read active domains.
	domOut, err := runQuery("SELECT domain, active, dkim_enabled, spam_filter_enabled, spam_header_score, spam_reject_score, outbound_hourly_limit FROM mail_domains WHERE active = 1 ORDER BY domain ASC")
	if err != nil {
		// Fallback to v_postfix_domains if lc_mail_ro only has view grants.
		viewOut, viewErr := runQuery("SELECT domain FROM v_postfix_domains ORDER BY domain ASC")
		if viewErr != nil {
			return ReconcileState{}, fmt.Errorf("query active mail domains: %w", err)
		}
		for _, line := range strings.Split(strings.TrimSpace(viewOut), "\n") {
			d := strings.TrimSpace(line)
			if d == "" {
				continue
			}
			state.Domains = append(state.Domains, MailDomainState{
				Domain:              d,
				Active:              true,
				DkimEnabled:         true,
				SpamFilterEnabled:   false,
				SpamHeaderScore:     6.0,
				SpamRejectScore:     15.0,
				OutboundHourlyLimit: 200,
			})
		}
	} else {
		for _, line := range strings.Split(strings.TrimSpace(domOut), "\n") {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}
			parts := strings.Split(line, "\t")
			if len(parts) < 7 {
				continue
			}
			hdrScore, _ := strconv.ParseFloat(parts[4], 64)
			rejScore, _ := strconv.ParseFloat(parts[5], 64)
			hourlyLimit, _ := strconv.Atoi(parts[6])
			state.Domains = append(state.Domains, MailDomainState{
				Domain:              parts[0],
				Active:              parts[1] == "1",
				DkimEnabled:         parts[2] == "1",
				SpamFilterEnabled:   parts[3] == "1",
				SpamHeaderScore:     hdrScore,
				SpamRejectScore:     rejScore,
				OutboundHourlyLimit: hourlyLimit,
			})
		}
	}

	// 2. Read active DKIM keys (best-effort if table isn't accessible to lc_mail_ro).
	dkimOut, err := runQuery("SELECT d.domain, k.selector, k.algorithm, k.private_key_path, k.key_sha256, k.status FROM mail_dkim_keys k INNER JOIN mail_domains d ON d.id = k.mail_domain_id WHERE d.active = 1 AND k.status IN ('active','rotating','pending') ORDER BY d.domain ASC, k.selector ASC")
	if err == nil {
		for _, line := range strings.Split(strings.TrimSpace(dkimOut), "\n") {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}
			parts := strings.Split(line, "\t")
			if len(parts) < 6 {
				continue
			}
			state.DkimKeys = append(state.DkimKeys, DkimKeyState{
				Domain:         parts[0],
				Selector:       parts[1],
				Algorithm:      parts[2],
				PrivateKeyPath: parts[3],
				KeySHA256:      parts[4],
				Status:         parts[5],
			})
		}
	}

	// 3. Resolve SSL cert paths from LiteControl's cert store.
	certStore := r.CertStoreDir
	if certStore == "" {
		certStore = filepath.Join(r.RootDir, "etc", "letsencrypt", "live")
	}
	for _, dom := range state.Domains {
		if pair, ok := findDomainCert(certStore, dom.Domain); ok {
			state.Certs[dom.Domain] = pair
		}
	}

	return state, nil
}

// findDomainCert checks mail.<domain> first, then <domain> in the cert store.
func findDomainCert(certStoreDir, domain string) (CertPair, bool) {
	candidates := []string{
		filepath.Join(certStoreDir, "mail."+domain),
		filepath.Join(certStoreDir, domain),
	}
	for _, dir := range candidates {
		cert := filepath.Join(dir, "fullchain.pem")
		key := filepath.Join(dir, "privkey.pem")
		if fileExists(cert) && fileExists(key) {
			return CertPair{CertPath: cert, KeyPath: key}, true
		}
	}
	return CertPair{}, false
}

func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

// FileSHA256 computes the lowercase hex SHA-256 digest of a file.
func FileSHA256(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), nil
}

// BytesSHA256 computes the lowercase hex SHA-256 digest of a byte slice.
func BytesSHA256(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}
