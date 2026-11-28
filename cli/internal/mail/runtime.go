package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

var (
	validLocalPartRe = regexp.MustCompile(`^[a-z0-9][a-z0-9._+-]{0,63}$`)
	validEmailRe     = regexp.MustCompile(`^[a-z0-9][a-z0-9._+-]{0,63}@[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$`)
)

// EvictSessionOptions configures `lc-mail evict-session`.
type EvictSessionOptions struct {
	Email  string
	Runner CommandRunner
}

// EvictSessionResult is emitted as JSON by `lc-mail evict-session`.
type EvictSessionResult struct {
	Status string `json:"status"`
	Email  string `json:"email"`
}

// EvictSession flushes Dovecot auth cache and kicks active sessions for an email address.
func EvictSession(ctx context.Context, opts EvictSessionOptions) (EvictSessionResult, error) {
	email := strings.ToLower(strings.TrimSpace(opts.Email))
	if !validEmailRe.MatchString(email) || strings.Contains(email, "..") {
		return EvictSessionResult{}, fmt.Errorf("invalid email address %q", opts.Email)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	if _, err := runner.Run(ctx, "doveadm", "auth", "cache", "flush", email); err != nil {
		return EvictSessionResult{}, fmt.Errorf("doveadm auth cache flush %s: %w", email, err)
	}
	if _, err := runner.Run(ctx, "doveadm", "kick", email); err != nil {
		return EvictSessionResult{}, fmt.Errorf("doveadm kick %s: %w", email, err)
	}

	return EvictSessionResult{
		Status: "success",
		Email:  email,
	}, nil
}

// PurgeMailboxOptions configures `lc-mail purge-mailbox`.
type PurgeMailboxOptions struct {
	Domain  string
	User    string
	RootDir string // Defaults to "/" on Linux; overridable in tests.
}

// PurgeMailboxResult is emitted as JSON by `lc-mail purge-mailbox`.
type PurgeMailboxResult struct {
	Status  string `json:"status"`
	Domain  string `json:"domain"`
	User    string `json:"user"`
	Path    string `json:"path"`
	Removed bool   `json:"removed"`
}

// PurgeMailbox safely deletes /var/vmail/<domain>/<user> after strict path containment
// and symlink validation.
func PurgeMailbox(_ context.Context, opts PurgeMailboxOptions) (PurgeMailboxResult, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if err := ValidateHostname(domain); err != nil {
		return PurgeMailboxResult{}, fmt.Errorf("invalid domain %q: %w", opts.Domain, err)
	}

	user := strings.ToLower(strings.TrimSpace(opts.User))
	if !validLocalPartRe.MatchString(user) || strings.Contains(user, "..") || strings.HasSuffix(user, ".") {
		return PurgeMailboxResult{}, fmt.Errorf("invalid mailbox user %q", opts.User)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	vmailBase := filepath.Clean(filepath.Join(rootDir, "var", "vmail"))
	domainDir := filepath.Clean(filepath.Join(vmailBase, domain))
	targetDir := filepath.Clean(filepath.Join(domainDir, user))

	// Strict lexical containment check.
	if !isStrictlyUnder(targetDir, vmailBase) || !isStrictlyUnder(domainDir, vmailBase) {
		return PurgeMailboxResult{}, fmt.Errorf("path traversal rejected: %s escapes %s", targetDir, vmailBase)
	}

	// Ensure neither domainDir nor targetDir is a symlink escaping vmailBase.
	if err := rejectSymlink(vmailBase); err != nil {
		return PurgeMailboxResult{}, err
	}
	if err := rejectSymlink(domainDir); err != nil {
		return PurgeMailboxResult{}, err
	}
	if err := rejectSymlink(targetDir); err != nil {
		return PurgeMailboxResult{}, err
	}

	if _, err := os.Lstat(targetDir); os.IsNotExist(err) {
		return PurgeMailboxResult{
			Status:  "success",
			Domain:  domain,
			User:    user,
			Path:    targetDir,
			Removed: false,
		}, nil
	} else if err != nil {
		return PurgeMailboxResult{}, fmt.Errorf("stat mailbox %s: %w", targetDir, err)
	}

	// Verify resolved real path of base and target remain strictly contained.
	realBase, err := filepath.EvalSymlinks(vmailBase)
	if err != nil {
		return PurgeMailboxResult{}, fmt.Errorf("resolve vmail base %s: %w", vmailBase, err)
	}
	realTarget, err := filepath.EvalSymlinks(targetDir)
	if err != nil {
		return PurgeMailboxResult{}, fmt.Errorf("resolve mailbox path %s: %w", targetDir, err)
	}
	if !isStrictlyUnder(realTarget, realBase) {
		return PurgeMailboxResult{}, fmt.Errorf("symlink escape rejected: %s resolves outside %s", realTarget, realBase)
	}

	if err := os.RemoveAll(targetDir); err != nil {
		return PurgeMailboxResult{}, fmt.Errorf("remove mailbox %s: %w", targetDir, err)
	}

	return PurgeMailboxResult{
		Status:  "success",
		Domain:  domain,
		User:    user,
		Path:    targetDir,
		Removed: true,
	}, nil
}

// PurgeDomainOptions configures `lc-mail purge-domain`.
type PurgeDomainOptions struct {
	Domain  string
	RootDir string // Defaults to "/" on Linux; overridable in tests.
}

// PurgeDomainResult is emitted as JSON by `lc-mail purge-domain`.
type PurgeDomainResult struct {
	Status          string   `json:"status"`
	Domain          string   `json:"domain"`
	Path            string   `json:"path"`
	Removed         bool     `json:"removed"`
	DkimKeysRemoved []string `json:"dkim_keys_removed"`
}

// PurgeDomain safely deletes /var/vmail/<domain> and any DKIM private keys
// matching /var/lib/rspamd/dkim/<domain>.*.key after strict path containment
// and symlink validation.
func PurgeDomain(_ context.Context, opts PurgeDomainOptions) (PurgeDomainResult, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if err := ValidateHostname(domain); err != nil {
		return PurgeDomainResult{}, fmt.Errorf("invalid domain %q: %w", opts.Domain, err)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	vmailBase := filepath.Clean(filepath.Join(rootDir, "var", "vmail"))
	domainDir := filepath.Clean(filepath.Join(vmailBase, domain))

	if !isStrictlyUnder(domainDir, vmailBase) {
		return PurgeDomainResult{}, fmt.Errorf("path traversal rejected: %s escapes %s", domainDir, vmailBase)
	}

	if err := rejectSymlink(vmailBase); err != nil {
		return PurgeDomainResult{}, err
	}
	if err := rejectSymlink(domainDir); err != nil {
		return PurgeDomainResult{}, err
	}

	removed := false
	if _, err := os.Lstat(domainDir); err == nil {
		realBase, err := filepath.EvalSymlinks(vmailBase)
		if err != nil {
			return PurgeDomainResult{}, fmt.Errorf("resolve vmail base %s: %w", vmailBase, err)
		}
		realTarget, err := filepath.EvalSymlinks(domainDir)
		if err != nil {
			return PurgeDomainResult{}, fmt.Errorf("resolve domain path %s: %w", domainDir, err)
		}
		if !isStrictlyUnder(realTarget, realBase) {
			return PurgeDomainResult{}, fmt.Errorf("symlink escape rejected: %s resolves outside %s", realTarget, realBase)
		}

		if err := os.RemoveAll(domainDir); err != nil {
			return PurgeDomainResult{}, fmt.Errorf("remove domain maildir %s: %w", domainDir, err)
		}
		removed = true
	} else if !os.IsNotExist(err) {
		return PurgeDomainResult{}, fmt.Errorf("stat domain maildir %s: %w", domainDir, err)
	}

	// Remove any DKIM private keys for this domain under /var/lib/rspamd/dkim.
	dkimDir := filepath.Clean(filepath.Join(rootDir, "var", "lib", "rspamd", "dkim"))
	dkimRemoved := []string{}
	if err := rejectSymlink(dkimDir); err != nil {
		return PurgeDomainResult{}, err
	}
	if entries, err := os.ReadDir(dkimDir); err == nil {
		prefix := domain + "."
		for _, entry := range entries {
			name := entry.Name()
			if !strings.HasPrefix(name, prefix) || !strings.HasSuffix(name, ".key") {
				continue
			}
			keyPath := filepath.Clean(filepath.Join(dkimDir, name))
			if !isStrictlyUnder(keyPath, dkimDir) {
				continue
			}
			if err := rejectSymlink(keyPath); err != nil {
				return PurgeDomainResult{}, err
			}
			if err := os.Remove(keyPath); err != nil && !os.IsNotExist(err) {
				return PurgeDomainResult{}, fmt.Errorf("remove dkim key %s: %w", keyPath, err)
			}
			dkimRemoved = append(dkimRemoved, keyPath)
		}
	} else if !os.IsNotExist(err) {
		return PurgeDomainResult{}, fmt.Errorf("read dkim dir %s: %w", dkimDir, err)
	}

	return PurgeDomainResult{
		Status:          "success",
		Domain:          domain,
		Path:            domainDir,
		Removed:         removed,
		DkimKeysRemoved: dkimRemoved,
	}, nil
}

func isStrictlyUnder(path, base string) bool {
	rel, err := filepath.Rel(base, path)
	if err != nil {
		return false
	}
	if rel == "." || rel == ".." || strings.HasPrefix(rel, ".."+string(os.PathSeparator)) {
		return false
	}
	return true
}

func rejectSymlink(path string) error {
	info, err := os.Lstat(path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("lstat %s: %w", path, err)
	}
	if info.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("symlink rejected at %s", path)
	}
	return nil
}

// QuotaUsageOptions configures `lc-mail quota-usage`.
type QuotaUsageOptions struct {
	Domain string
	Runner CommandRunner
}

// QuotaUsage runs `doveadm -f tab quota get -u "*@<domain>"` and returns {email: used_bytes}.
func QuotaUsage(ctx context.Context, opts QuotaUsageOptions) (map[string]int64, error) {
	domain := strings.ToLower(strings.TrimSpace(opts.Domain))
	if err := ValidateHostname(domain); err != nil {
		return nil, fmt.Errorf("invalid domain %q: %w", opts.Domain, err)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	out, err := runner.Run(ctx, "doveadm", "-f", "tab", "quota", "get", "-u", "*@"+domain)
	if err != nil {
		return nil, fmt.Errorf("doveadm quota get for %s: %w", domain, err)
	}

	return ParseDoveadmQuotaTab(out), nil
}

// ParseDoveadmQuotaTab parses `doveadm -f tab quota get` output into {email: used_bytes}.
// Expected columns: Username \t Quota name \t Type \t Value \t Limit \t %
// STORAGE values in Dovecot are reported in KiB (1024 bytes).
func ParseDoveadmQuotaTab(output string) map[string]int64 {
	usage := make(map[string]int64)
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		cols := strings.Split(line, "\t")
		if len(cols) < 4 {
			continue
		}
		if strings.EqualFold(cols[0], "Username") {
			continue
		}
		if !strings.EqualFold(strings.TrimSpace(cols[2]), "STORAGE") {
			continue
		}
		email := strings.ToLower(strings.TrimSpace(cols[0]))
		kib, err := strconv.ParseInt(strings.TrimSpace(cols[3]), 10, 64)
		if err != nil || kib < 0 {
			continue
		}
		usage[email] = kib * 1024
	}
	return usage
}
