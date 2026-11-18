package deploy

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// RollbackOptions holds parameters for `lc-cli deploy rollback`.
type RollbackOptions struct {
	User      string
	Domain    string
	Release   string
	PHPBinary string
}

// RollbackDomain atomically switches the domain's `current` symlink to a
// retained release, resets LSPHP workers, and restarts Laravel queues if present.
func RollbackDomain(ctx context.Context, opts RollbackOptions) error {
	if opts.User == "" || opts.Domain == "" || opts.Release == "" {
		return fmt.Errorf("user, domain, and release are required")
	}
	if strings.Contains(opts.Release, "/") || strings.Contains(opts.Release, "\\") || opts.Release == ".." || opts.Release == "." {
		return fmt.Errorf("invalid release identifier: %s", opts.Release)
	}

	domainRoot := filepath.Join("/home", opts.User, opts.Domain)
	targetReleaseDir := filepath.Join(domainRoot, "releases", opts.Release)
	currentLink := filepath.Join(domainRoot, "current")

	info, err := os.Stat(targetReleaseDir)
	if err != nil {
		return fmt.Errorf("release directory %s not found: %w", targetReleaseDir, err)
	}
	if !info.IsDir() {
		return fmt.Errorf("release path %s is not a directory", targetReleaseDir)
	}

	uid, gid, err := lookupUserIDs(opts.User)
	if err != nil {
		return fmt.Errorf("lookup user %s: %w", opts.User, err)
	}

	if err := AtomicSymlink(targetReleaseDir, currentLink, uid, gid); err != nil {
		return fmt.Errorf("switch current symlink to %s: %w", opts.Release, err)
	}

	if err := resetLsphpWorkers(ctx, opts.User); err != nil {
		return err
	}

	artisanPath := filepath.Join(targetReleaseDir, "artisan")
	if _, err := os.Stat(artisanPath); err == nil && opts.PHPBinary != "" {
		cmdStr := fmt.Sprintf("%q %q queue:restart", opts.PHPBinary, artisanPath)
		if err := RunAsTenantUser(ctx, opts.User, targetReleaseDir, nil, cmdStr, os.Stderr); err != nil {
			return fmt.Errorf("restart queue workers: %w", err)
		}
	}

	return nil
}
