package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
)

// UninstallOptions configures `lc-mail uninstall-core`.
type UninstallOptions struct {
	PurgeData bool
	RootDir   string // Defaults to "/" on Linux; overridable in tests.
	Runner    CommandRunner
}

// UninstallResult summarizes the uninstall operation.
type UninstallResult struct {
	Status     string `json:"status"`
	PurgedData bool   `json:"purged_data"`
}

// UninstallCore stops and disables the mail stack and dedicated lc-rspamd-redis service.
// Preserves /var/vmail unless PurgeData is explicitly true.
func UninstallCore(ctx context.Context, opts UninstallOptions) (UninstallResult, error) {
	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	// Stop and disable mail stack + dedicated lc-rspamd-redis (never touch Laravel's Redis).
	services := []string{"postfix", "dovecot", "rspamd", "postsrsd", "lc-rspamd-redis"}
	for _, svc := range services {
		_, _ = runner.Run(ctx, "systemctl", "disable", "--now", svc)
	}

	// Remove dedicated lc-rspamd-redis unit file and reload systemd.
	unitPath := filepath.Join(rootDir, "etc", "systemd", "system", "lc-rspamd-redis.service")
	if err := os.Remove(unitPath); err != nil && !os.IsNotExist(err) {
		return UninstallResult{}, fmt.Errorf("remove %s: %w", unitPath, err)
	}
	if _, err := runner.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return UninstallResult{}, fmt.Errorf("systemctl daemon-reload: %w", err)
	}

	if opts.PurgeData {
		vmailPath := filepath.Join(rootDir, "var", "vmail")
		if err := os.RemoveAll(vmailPath); err != nil {
			return UninstallResult{}, fmt.Errorf("purge /var/vmail: %w", err)
		}
	}

	return UninstallResult{
		Status:     "success",
		PurgedData: opts.PurgeData,
	}, nil
}
