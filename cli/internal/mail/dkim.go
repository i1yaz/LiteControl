package mail

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

var validDkimSelectorRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

// DkimGenerateOptions configures `lc-mail dkim-generate`.
type DkimGenerateOptions struct {
	Domain    string
	Selector  string
	Algorithm string // rsa2048 (default) or ed25519
	RootDir   string // Defaults to "/" on Linux; overridable in tests.
	Runner    CommandRunner
}

// DkimGenerateResult is emitted as JSON by `lc-mail dkim-generate`.
type DkimGenerateResult struct {
	PublicKeyTxt   string `json:"public_key_txt"`
	PrivateKeyPath string `json:"private_key_path"`
	KeySha256      string `json:"key_sha256"`
}

// DkimGenerate runs `rspamadm dkim_keygen`, writes a 0600 _rspamd:_rspamd private key
// to /var/lib/rspamd/dkim/{domain}.{selector}.key, and returns the public TXT record,
// private key path, and SHA-256 fingerprint.
func DkimGenerate(ctx context.Context, opts DkimGenerateOptions) (DkimGenerateResult, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if err := ValidateHostname(domain); err != nil {
		return DkimGenerateResult{}, fmt.Errorf("invalid domain %q: %w", opts.Domain, err)
	}

	selector := strings.ToLower(strings.TrimSpace(opts.Selector))
	if !validDkimSelectorRe.MatchString(selector) {
		return DkimGenerateResult{}, fmt.Errorf("invalid DKIM selector %q", opts.Selector)
	}

	algo := strings.ToLower(strings.TrimSpace(opts.Algorithm))
	if algo == "" {
		algo = "rsa2048"
	}

	var keygenArgs []string
	switch algo {
	case "rsa2048":
		keygenArgs = []string{"dkim_keygen", "-s", selector, "-d", domain, "-b", "2048", "-t", "rsa"}
	case "ed25519":
		keygenArgs = []string{"dkim_keygen", "-s", selector, "-d", domain, "-t", "ed25519"}
	default:
		return DkimGenerateResult{}, fmt.Errorf("unsupported DKIM algorithm %q (expected rsa2048 or ed25519)", opts.Algorithm)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	dkimDir := filepath.Clean(filepath.Join(rootDir, "var", "lib", "rspamd", "dkim"))
	keyFile := filepath.Clean(filepath.Join(dkimDir, fmt.Sprintf("%s.%s.key", domain, selector)))

	if !isStrictlyUnder(keyFile, dkimDir) {
		return DkimGenerateResult{}, fmt.Errorf("path traversal rejected: %s escapes %s", keyFile, dkimDir)
	}

	if err := os.MkdirAll(dkimDir, 0o750); err != nil {
		return DkimGenerateResult{}, fmt.Errorf("create dkim directory %s: %w", dkimDir, err)
	}
	if err := rejectSymlink(dkimDir); err != nil {
		return DkimGenerateResult{}, err
	}
	if err := rejectSymlink(keyFile); err != nil {
		return DkimGenerateResult{}, err
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	keygenArgs = append(keygenArgs, "-k", keyFile)
	out, err := runner.Run(ctx, "rspamadm", keygenArgs...)
	if err != nil {
		return DkimGenerateResult{}, fmt.Errorf("rspamadm dkim_keygen for %s (%s): %w", domain, selector, err)
	}

	if err := os.Chmod(keyFile, 0o600); err != nil {
		return DkimGenerateResult{}, fmt.Errorf("chmod 0600 %s: %w", keyFile, err)
	}
	chownRspamdBestEffort(keyFile)

	keyBytes, err := os.ReadFile(keyFile)
	if err != nil {
		return DkimGenerateResult{}, fmt.Errorf("read generated private key %s: %w", keyFile, err)
	}
	sum := sha256.Sum256(keyBytes)

	publicTxt := ParseDkimKeygenOutput(out)
	if publicTxt == "" {
		return DkimGenerateResult{}, fmt.Errorf("could not parse public DKIM TXT record from rspamadm output")
	}

	return DkimGenerateResult{
		PublicKeyTxt:   publicTxt,
		PrivateKeyPath: keyFile,
		KeySha256:      hex.EncodeToString(sum[:]),
	}, nil
}

// ParseDkimKeygenOutput extracts a clean `v=DKIM1; ...` TXT value from `rspamadm dkim_keygen` output.
func ParseDkimKeygenOutput(output string) string {
	trimmed := strings.TrimSpace(output)
	if trimmed == "" {
		return ""
	}

	// If rspamadm outputs quoted chunks like:
	// selector._domainkey IN TXT ( "v=DKIM1; k=rsa; " "p=MIIBIj..." ) ;
	// extract and concatenate all double-quoted strings.
	var parts []string
	inQuote := false
	var current strings.Builder
	for i := 0; i < len(trimmed); i++ {
		ch := trimmed[i]
		if ch == '"' {
			if inQuote {
				parts = append(parts, current.String())
				current.Reset()
				inQuote = false
			} else {
				inQuote = true
			}
			continue
		}
		if inQuote {
			current.WriteByte(ch)
		}
	}

	if len(parts) > 0 {
		joined := strings.TrimSpace(strings.Join(parts, ""))
		if strings.Contains(joined, "v=DKIM1") {
			return joined
		}
	}

	// Fallback: raw v=DKIM1 line without quotes.
	if idx := strings.Index(trimmed, "v=DKIM1"); idx >= 0 {
		return strings.TrimSpace(trimmed[idx:])
	}

	return ""
}

func chownRspamdBestEffort(path string) {
	u, err := user.Lookup("_rspamd")
	if err != nil {
		return
	}
	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return
	}
	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return
	}
	_ = os.Chown(path, uid, gid)
}

// DkimRemoveOptions configures `lc-mail dkim-remove`.
type DkimRemoveOptions struct {
	Domain        string
	Selector      string
	RootDir       string // Defaults to "/" on Linux; overridable in tests.
	SkipReconcile bool
	ReconcileFunc func(ctx context.Context, scope string) error
}

// DkimRemoveResult is emitted as JSON by `lc-mail dkim-remove`.
type DkimRemoveResult struct {
	Status   string `json:"status"`
	Domain   string `json:"domain"`
	Selector string `json:"selector"`
	Path     string `json:"path"`
	Removed  bool   `json:"removed"`
}

// DkimRemove safely unlinks /var/lib/rspamd/dkim/{domain}.{selector}.key and triggers
// `reconcile --scope=dkim`.
func DkimRemove(ctx context.Context, opts DkimRemoveOptions) (DkimRemoveResult, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if err := ValidateHostname(domain); err != nil {
		return DkimRemoveResult{}, fmt.Errorf("invalid domain %q: %w", opts.Domain, err)
	}

	selector := strings.ToLower(strings.TrimSpace(opts.Selector))
	if !validDkimSelectorRe.MatchString(selector) {
		return DkimRemoveResult{}, fmt.Errorf("invalid DKIM selector %q", opts.Selector)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	dkimDir := filepath.Clean(filepath.Join(rootDir, "var", "lib", "rspamd", "dkim"))
	keyFile := filepath.Clean(filepath.Join(dkimDir, fmt.Sprintf("%s.%s.key", domain, selector)))

	if !isStrictlyUnder(keyFile, dkimDir) {
		return DkimRemoveResult{}, fmt.Errorf("path traversal rejected: %s escapes %s", keyFile, dkimDir)
	}
	if err := rejectSymlink(dkimDir); err != nil {
		return DkimRemoveResult{}, err
	}
	if err := rejectSymlink(keyFile); err != nil {
		return DkimRemoveResult{}, err
	}

	removed := false
	if _, err := os.Lstat(keyFile); err == nil {
		if err := os.Remove(keyFile); err != nil {
			return DkimRemoveResult{}, fmt.Errorf("remove dkim key %s: %w", keyFile, err)
		}
		removed = true
	} else if !os.IsNotExist(err) {
		return DkimRemoveResult{}, fmt.Errorf("stat dkim key %s: %w", keyFile, err)
	}

	if !opts.SkipReconcile && opts.ReconcileFunc != nil {
		if err := opts.ReconcileFunc(ctx, "dkim"); err != nil {
			return DkimRemoveResult{}, fmt.Errorf("reconcile dkim after key removal: %w", err)
		}
	}

	return DkimRemoveResult{
		Status:   "success",
		Domain:   domain,
		Selector: selector,
		Path:     keyFile,
		Removed:  removed,
	}, nil
}
