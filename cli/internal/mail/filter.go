package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

const (
	// MinClamAVRamMB is the minimum host RAM required before enabling ClamAV.
	MinClamAVRamMB = 4096

	// RedisMaxMemoryLight is the Rspamd-Light Redis memory cap.
	RedisMaxMemoryLight = "32mb"

	// RedisMaxMemoryFull is the full Anti-Spam Rspamd Redis memory cap (128mb-256mb).
	RedisMaxMemoryFull = "256mb"

	// RspamdMilterAddress is the local Rspamd proxy milter endpoint.
	RspamdMilterAddress = "inet:127.0.0.1:11332"
)

// FilterEnableOptions configures `lc-mail filter-enable`.
type FilterEnableOptions struct {
	Driver        string // rspamd (default)
	WithClamAV    bool
	RootDir       string // Defaults to "/" on Linux; overridable in tests.
	DovecotSeries DovecotSeries
	SkipPackages  bool
	SkipReconcile bool
	Runner        CommandRunner
	TotalMemoryMB func() (int, error)
	ReconcileFunc func(ctx context.Context, scope string) error
}

// FilterEnableResult is emitted as JSON by `lc-mail filter-enable`.
type FilterEnableResult struct {
	Status        string `json:"status"`
	Driver        string `json:"driver"`
	Mode          string `json:"mode"`
	WithClamAV    bool   `json:"with_clamav"`
	RedisMemory   string `json:"redis_memory"`
	SmtpdMilters  string `json:"smtpd_milters"`
	DovecotSeries string `json:"dovecot_series"`
}

// FilterDemoteOptions configures `lc-mail filter-demote`.
type FilterDemoteOptions struct {
	Driver        string // rspamd (default)
	RootDir       string // Defaults to "/" on Linux; overridable in tests.
	SkipReconcile bool
	Runner        CommandRunner
	ReconcileFunc func(ctx context.Context, scope string) error
}

// FilterDemoteResult is emitted as JSON by `lc-mail filter-demote`.
type FilterDemoteResult struct {
	Status        string `json:"status"`
	Driver        string `json:"driver"`
	Mode          string `json:"mode"`
	RedisMemory   string `json:"redis_memory"`
	SmtpdMilters  string `json:"smtpd_milters"`
	DkimPreserved bool   `json:"dkim_preserved"`
}

// FilterStatusOptions configures `lc-mail filter-status`.
type FilterStatusOptions struct {
	Driver  string
	RootDir string
	Runner  CommandRunner
}

// FilterStatusResult is emitted as JSON by `lc-mail filter-status`.
type FilterStatusResult struct {
	Driver     string `json:"driver"`
	Mode       string `json:"mode"`
	Running    bool   `json:"running"`
	WithClamAV bool   `json:"with_clamav"`
}

// FilterEnable upgrades Rspamd-Light to full inbound Anti-Spam (and optional ClamAV):
//   - Enforces >= 4096 MB RAM pre-flight check when WithClamAV is true.
//   - Raises lc-rspamd-redis maxmemory to 256mb.
//   - Enables global inbound port-25 milter (smtpd_milters = inet:127.0.0.1:11332).
//   - Enables full Rspamd rules and Sieve Junk auto-filing (Dovecot 2.3 & 2.4).
//   - Runs reconcile --scope=filter.
func FilterEnable(ctx context.Context, opts FilterEnableOptions) (FilterEnableResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "rspamd"
	}
	if driver != "rspamd" {
		return FilterEnableResult{}, fmt.Errorf("unsupported mail filter driver %q (expected rspamd)", opts.Driver)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	if opts.WithClamAV {
		memFn := opts.TotalMemoryMB
		if memFn == nil {
			memFn = func() (int, error) { return ReadTotalMemoryMB(rootDir) }
		}
		ramMB, err := memFn()
		if err != nil {
			return FilterEnableResult{}, fmt.Errorf("read host RAM for ClamAV pre-flight check: %w", err)
		}
		if ramMB < MinClamAVRamMB {
			return FilterEnableResult{}, fmt.Errorf(
				"insufficient RAM for ClamAV: %d MB available, minimum %d MB required",
				ramMB, MinClamAVRamMB,
			)
		}
	}

	// Detect Dovecot series for Sieve Junk auto-filing template.
	dovecotSeries := opts.DovecotSeries
	if dovecotSeries == "" {
		if verOut, err := runner.Run(ctx, "dovecot", "--version"); err == nil {
			if _, series, err := DetectDovecotSeries(verOut); err == nil {
				dovecotSeries = series
			}
		}
		if dovecotSeries == "" {
			dovecotSeries = Dovecot23
		}
	}

	// 1. Raise lc-rspamd-redis maxmemory to 256mb.
	redisConfAbs := filepath.Join(rootDir, "etc", "lc-rspamd-redis", "redis.conf")
	if err := updateRedisMaxMemory(redisConfAbs, RedisMaxMemoryFull); err != nil {
		return FilterEnableResult{}, fmt.Errorf("update lc-rspamd-redis maxmemory: %w", err)
	}
	_, _ = runner.Run(ctx, "systemctl", "restart", "lc-rspamd-redis")

	// 2. Enable global inbound port-25 milter in Postfix main.cf.
	mainCfAbs := filepath.Join(rootDir, "etc", "postfix", "main.cf")
	existingMainCf, _ := os.ReadFile(mainCfAbs)
	updatedMainCf := updatePostfixMilters(string(existingMainCf), RspamdMilterAddress, RspamdMilterAddress)
	if err := WriteFileAtomic(mainCfAbs, []byte(updatedMainCf), 0o644); err != nil {
		return FilterEnableResult{}, fmt.Errorf("update postfix main.cf milters: %w", err)
	}

	// 3. Render full Rspamd rules (milter_headers, actions, and optional ClamAV antivirus).
	milterHeadersConf := `# Managed by LiteControl lc-mail filter-enable
use = ["x-spamd-bar", "x-spam-level", "x-spam-status", "authentication-results"];
authenticated_headers = ["authentication-results"];
`
	if err := WriteFileAtomic(
		filepath.Join(rootDir, "etc", "rspamd", "local.d", "milter_headers.conf"),
		[]byte(milterHeadersConf),
		0o644,
	); err != nil {
		return FilterEnableResult{}, fmt.Errorf("write rspamd milter_headers.conf: %w", err)
	}

	actionsConf := `# Managed by LiteControl lc-mail filter-enable
reject = 15;
add_header = 6;
greylist = 4;
`
	if err := WriteFileAtomic(
		filepath.Join(rootDir, "etc", "rspamd", "local.d", "actions.conf"),
		[]byte(actionsConf),
		0o644,
	); err != nil {
		return FilterEnableResult{}, fmt.Errorf("write rspamd actions.conf: %w", err)
	}

	antivirusPath := filepath.Join(rootDir, "etc", "rspamd", "local.d", "antivirus.conf")
	if opts.WithClamAV {
		if !opts.SkipPackages {
			if osInfo, err := DetectOS(rootDir); err == nil {
				if osInfo.Family == OSFamilyRHEL {
					_, _ = runner.Run(ctx, "dnf", "install", "-y", "clamav", "clamd", "clamav-update")
					_, _ = runner.Run(ctx, "systemctl", "enable", "--now", "clamd@scan")
				} else {
					_, _ = runner.Run(ctx, "apt-get", "install", "-y", "clamav-daemon", "clamav-freshclam")
					_, _ = runner.Run(ctx, "systemctl", "enable", "--now", "clamav-daemon")
				}
			}
		}

		antivirusConf := `# Managed by LiteControl lc-mail filter-enable (--with-clamav)
clamav {
  action = "reject";
  symbol = "CLAM_VIRUS";
  type = "clamav";
  servers = "/run/clamav/clamd.ctl";
  scan_mime_parts = true;
}
`
		if err := WriteFileAtomic(antivirusPath, []byte(antivirusConf), 0o644); err != nil {
			return FilterEnableResult{}, fmt.Errorf("write rspamd antivirus.conf: %w", err)
		}
	}

	// 4. Enable Sieve Junk auto-filing for Dovecot 2.3 and 2.4.
	sieveScriptAbs := filepath.Join(rootDir, "etc", "dovecot", "sieve", "before", "10-spam-to-junk.sieve")
	sieveScript := `# Managed by LiteControl lc-mail filter-enable
require ["fileinto", "mailbox"];
if header :contains "X-Spam-Flag" "YES" {
  fileinto :create "Junk";
  stop;
}
if header :contains "X-Spam-Status" "Yes," {
  fileinto :create "Junk";
  stop;
}
`
	if err := WriteFileAtomic(sieveScriptAbs, []byte(sieveScript), 0o644); err != nil {
		return FilterEnableResult{}, fmt.Errorf("write sieve spam script: %w", err)
	}
	_, _ = runner.Run(ctx, "sievec", sieveScriptAbs)

	sieveConfAbs := filepath.Join(rootDir, "etc", "dovecot", "conf.d", "95-lc-sieve-spam.conf")
	sieveConf := renderDovecotSieveSpamConf(dovecotSeries)
	if err := WriteFileAtomic(sieveConfAbs, []byte(sieveConf), 0o644); err != nil {
		return FilterEnableResult{}, fmt.Errorf("write dovecot sieve spam conf: %w", err)
	}

	// 5. Mark filter mode state file for status checks.
	modeMarker := "full\n"
	if opts.WithClamAV {
		modeMarker = "full+clamav\n"
	}
	if err := WriteFileAtomic(
		filepath.Join(rootDir, "etc", "rspamd", "local.d", "lc_filter_mode"),
		[]byte(modeMarker),
		0o644,
	); err != nil {
		return FilterEnableResult{}, fmt.Errorf("write filter mode marker: %w", err)
	}

	// 6. Trigger reconcile --scope=filter.
	if !opts.SkipReconcile && opts.ReconcileFunc != nil {
		if err := opts.ReconcileFunc(ctx, "filter"); err != nil {
			return FilterEnableResult{}, fmt.Errorf("reconcile filter after enable: %w", err)
		}
	}

	return FilterEnableResult{
		Status:        "success",
		Driver:        driver,
		Mode:          "full",
		WithClamAV:    opts.WithClamAV,
		RedisMemory:   RedisMaxMemoryFull,
		SmtpdMilters:  RspamdMilterAddress,
		DovecotSeries: string(dovecotSeries),
	}, nil
}

// FilterDemote demotes Rspamd back to DKIM-Light + outbound ratelimit mode without
// removing the Rspamd package or touching any DKIM keys.
func FilterDemote(ctx context.Context, opts FilterDemoteOptions) (FilterDemoteResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "rspamd"
	}
	if driver != "rspamd" {
		return FilterDemoteResult{}, fmt.Errorf("unsupported mail filter driver %q (expected rspamd)", opts.Driver)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	// 1. Disable ClamAV services & remove antivirus config (best-effort service stop).
	_, _ = runner.Run(ctx, "systemctl", "disable", "--now", "clamav-daemon")
	_, _ = runner.Run(ctx, "systemctl", "disable", "--now", "clamd@scan")
	_ = os.Remove(filepath.Join(rootDir, "etc", "rspamd", "local.d", "antivirus.conf"))

	// 2. Remove full-mode inbound scanning configs & Dovecot Sieve spam drop-in.
	// Never touch dkim_signing.conf, ratelimit.conf, or /var/lib/rspamd/dkim/*.
	_ = os.Remove(filepath.Join(rootDir, "etc", "rspamd", "local.d", "milter_headers.conf"))
	_ = os.Remove(filepath.Join(rootDir, "etc", "rspamd", "local.d", "actions.conf"))
	_ = os.Remove(filepath.Join(rootDir, "etc", "dovecot", "conf.d", "95-lc-sieve-spam.conf"))

	// 3. Clear global inbound port-25 smtpd_milters while preserving non_smtpd_milters.
	mainCfAbs := filepath.Join(rootDir, "etc", "postfix", "main.cf")
	existingMainCf, _ := os.ReadFile(mainCfAbs)
	updatedMainCf := updatePostfixMilters(string(existingMainCf), "", RspamdMilterAddress)
	if err := WriteFileAtomic(mainCfAbs, []byte(updatedMainCf), 0o644); err != nil {
		return FilterDemoteResult{}, fmt.Errorf("clear postfix smtpd_milters: %w", err)
	}

	// 4. Lower lc-rspamd-redis maxmemory back to 32mb.
	redisConfAbs := filepath.Join(rootDir, "etc", "lc-rspamd-redis", "redis.conf")
	if err := updateRedisMaxMemory(redisConfAbs, RedisMaxMemoryLight); err != nil {
		return FilterDemoteResult{}, fmt.Errorf("lower lc-rspamd-redis maxmemory: %w", err)
	}
	_, _ = runner.Run(ctx, "systemctl", "restart", "lc-rspamd-redis")

	// 5. Update mode marker to light.
	if err := WriteFileAtomic(
		filepath.Join(rootDir, "etc", "rspamd", "local.d", "lc_filter_mode"),
		[]byte("light\n"),
		0o644,
	); err != nil {
		return FilterDemoteResult{}, fmt.Errorf("write light mode marker: %w", err)
	}

	// 6. Trigger reconcile --scope=filter.
	if !opts.SkipReconcile && opts.ReconcileFunc != nil {
		if err := opts.ReconcileFunc(ctx, "filter"); err != nil {
			return FilterDemoteResult{}, fmt.Errorf("reconcile filter after demote: %w", err)
		}
	}

	return FilterDemoteResult{
		Status:        "success",
		Driver:        driver,
		Mode:          "light",
		RedisMemory:   RedisMaxMemoryLight,
		SmtpdMilters:  "",
		DkimPreserved: true,
	}, nil
}

// FilterStatus checks whether the full Anti-Spam filter is active and running.
func FilterStatus(ctx context.Context, opts FilterStatusOptions) (FilterStatusResult, error) {
	driver := strings.ToLower(strings.TrimSpace(opts.Driver))
	if driver == "" {
		driver = "rspamd"
	}
	if driver != "rspamd" {
		return FilterStatusResult{}, fmt.Errorf("unsupported mail filter driver %q (expected rspamd)", opts.Driver)
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	modeBytes, _ := os.ReadFile(filepath.Join(rootDir, "etc", "rspamd", "local.d", "lc_filter_mode"))
	marker := strings.TrimSpace(string(modeBytes))

	mode := "light"
	withClamAV := false
	if marker == "full" || marker == "full+clamav" {
		mode = "full"
		withClamAV = marker == "full+clamav"
	}

	running := false
	if mode == "full" {
		if out, err := runner.Run(ctx, "systemctl", "is-active", "rspamd"); err == nil && strings.TrimSpace(out) == "active" {
			running = true
		}
	}

	return FilterStatusResult{
		Driver:     driver,
		Mode:       mode,
		Running:    running,
		WithClamAV: withClamAV,
	}, nil
}

// ReadTotalMemoryMB parses /proc/meminfo MemTotal and returns total RAM in megabytes.
func ReadTotalMemoryMB(rootDir string) (int, error) {
	meminfoPath := filepath.Join(rootDir, "proc", "meminfo")
	data, err := os.ReadFile(meminfoPath)
	if err != nil {
		return 0, err
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "MemTotal:") {
			fields := strings.Fields(line)
			if len(fields) >= 2 {
				kb, err := strconv.Atoi(fields[1])
				if err != nil {
					return 0, fmt.Errorf("parse MemTotal %q: %w", fields[1], err)
				}
				return kb / 1024, nil
			}
		}
	}
	return 0, fmt.Errorf("MemTotal not found in %s", meminfoPath)
}

func updateRedisMaxMemory(confPath string, maxMem string) error {
	existing, _ := os.ReadFile(confPath)
	content := strings.TrimRight(string(existing), "\n")
	if content == "" {
		content = "# Managed by LiteControl lc-mail\nmaxmemory " + maxMem + "\nmaxmemory-policy volatile-ttl\n"
		return WriteFileAtomic(confPath, []byte(content), 0o640)
	}

	lines := strings.Split(content, "\n")
	replaced := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "maxmemory ") {
			lines[i] = "maxmemory " + maxMem
			replaced = true
			break
		}
	}
	if !replaced {
		lines = append(lines, "maxmemory "+maxMem)
	}
	return WriteFileAtomic(confPath, []byte(strings.Join(lines, "\n")+"\n"), 0o640)
}

func renderDovecotSieveSpamConf(series DovecotSeries) string {
	if series == Dovecot24 {
		return `# Managed by LiteControl lc-mail filter-enable (Dovecot 2.4+)
sieve_script before_spam {
  type = before
  driver = file
  path = /etc/dovecot/sieve/before/10-spam-to-junk.sieve
}
`
	}
	return `# Managed by LiteControl lc-mail filter-enable (Dovecot 2.3)
plugin {
  sieve_before = /etc/dovecot/sieve/before/10-spam-to-junk.sieve
}
`
}
