package deploy

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"time"
)

// ErrRolledBack indicates that a deployment failed health checks and was
// automatically rolled back to the previous release.
var ErrRolledBack = errors.New("deployment failed health check and was rolled back")

// DeployResult is the structured JSON output emitted by `lc-cli deploy run`.
type DeployResult struct {
	Status        string `json:"status"`
	CommitSHA     string `json:"commit_sha"`
	CommitMessage string `json:"commit_message"`
	CommitAuthor  string `json:"commit_author"`
}

// RunDeploy executes the 4-stage zero-downtime deployment pipeline for specPath.
func RunDeploy(ctx context.Context, specPath string, logWriter io.Writer) (DeployResult, error) {
	return RunDeployWithFetch(ctx, specPath, nil, logWriter)
}

// RunDeployWithFetch executes the 4-stage zero-downtime deployment pipeline for specPath,
// optionally using a versioned stdin FetchPayload for provider-mode fetches.
func RunDeployWithFetch(ctx context.Context, specPath string, fetchPayload *FetchPayload, logWriter io.Writer) (DeployResult, error) {
	_ = SweepStaleAskpassDirs("", DefaultStaleAskpassAge)

	raw, err := os.ReadFile(specPath)
	if err != nil {
		return DeployResult{}, fmt.Errorf("read spec %s: %w", specPath, err)
	}

	var spec Payload
	if err := json.Unmarshal(raw, &spec); err != nil {
		return DeployResult{}, fmt.Errorf("parse spec %s: %w", specPath, err)
	}

	uid, gid, err := lookupUIDGID(spec.LinuxUser)
	if err != nil {
		return DeployResult{}, err
	}

	requiredDirs := []string{
		filepath.Join(spec.DomainRoot, "releases"),
		filepath.Join(spec.DomainRoot, "shared"),
		filepath.Join(spec.DomainRoot, ".deploy"),
		filepath.Join(spec.DomainRoot, ".deploy", "locks"),
		filepath.Join(spec.DomainRoot, ".deploy", "logs"),
		filepath.Join(spec.DomainRoot, ".deploy", "keys"),
		filepath.Join(spec.DomainRoot, ".deploy", "payloads"),
	}
	for _, d := range requiredDirs {
		if err := mkdirAllOwned(d, 0o750, uid, gid); err != nil {
			return DeployResult{}, err
		}
	}

	if spec.Repo.SSHKeyPath != "" {
		stagedKeyPath := filepath.Join(spec.DomainRoot, ".deploy", "keys", "deploy_key")
		if filepath.Clean(spec.Repo.SSHKeyPath) != filepath.Clean(stagedKeyPath) {
			if err := copyFileOwned(spec.Repo.SSHKeyPath, stagedKeyPath, 0o600, uid, gid); err != nil {
				return DeployResult{}, fmt.Errorf("stage ssh deploy key: %w", err)
			}
			spec.Repo.SSHKeyPath = stagedKeyPath
		}
	}

	lockDir := filepath.Join(spec.DomainRoot, ".deploy", "locks")
	lockFile, err := acquireDeployLock(filepath.Join(lockDir, "deploy.lock"))
	if err != nil {
		return DeployResult{}, err
	}
	defer releaseDeployLock(lockFile)

	currentLink := filepath.Join(spec.DomainRoot, "current")
	previousReleaseDir, _ := os.Readlink(currentLink)

	purged := false
	defer func() {
		if purged {
			return
		}
		activeDir, _ := os.Readlink(currentLink)
		if activeDir == "" {
			activeDir = previousReleaseDir
		}
		if err := purgeOldReleases(spec.DomainRoot, spec.KeepReleases, activeDir); err != nil {
			logStep(logWriter, fmt.Sprintf("Warning: failed to purge old releases: %v", err))
		}
	}()

	releaseDir := filepath.Join(spec.DomainRoot, "releases", spec.ReleaseID)
	var meta CommitMeta

	logStep(logWriter, fmt.Sprintf("Stage 1/4: Fetching release %s from %s (%s)...", spec.ReleaseID, spec.Repo.URL, spec.Repo.Branch))

	// Stage 1: Clone
	if err := RunHooks(ctx, "before_clone", spec.Hooks.BeforeClone, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}
	meta, err = FetchReleaseCodeWithPayload(ctx, spec, fetchPayload, releaseDir, logWriter)
	if err != nil {
		return DeployResult{}, fmt.Errorf("fetch release code: %w", err)
	}
	if err := LinkSharedPaths(spec, releaseDir, uid, gid); err != nil {
		return DeployResult{}, fmt.Errorf("link shared paths: %w", err)
	}
	restoreContextBestEffort(releaseDir, logWriter)
	restoreContextBestEffort(filepath.Join(spec.DomainRoot, "shared"), logWriter)
	if err := RunHooks(ctx, "after_clone", spec.Hooks.AfterClone, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}

	// Stage 2: Install
	logStep(logWriter, "Stage 2/4: Running install hooks...")
	if err := RunHooks(ctx, "before_install", spec.Hooks.BeforeInstall, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}
	if err := RunHooks(ctx, "install", spec.Hooks.Install, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}
	if err := RunHooks(ctx, "after_install", spec.Hooks.AfterInstall, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}

	// Stage 3: Activate
	logStep(logWriter, "Stage 3/4: Activating release and reloading lsphp workers...")
	if err := RunHooks(ctx, "before_activate", spec.Hooks.BeforeActivate, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}
	if err := AtomicSymlink(releaseDir, currentLink, uid, gid); err != nil {
		return DeployResult{}, fmt.Errorf("activate current symlink: %w", err)
	}
	if err := ensurePublicHTMLSymlink(spec.DomainRoot, spec.WebSubpath, uid, gid); err != nil {
		return DeployResult{}, fmt.Errorf("ensure public_html symlink: %w", err)
	}
	restoreContextBestEffort(filepath.Join(spec.DomainRoot, "public_html"), logWriter)
	restoreContextBestEffort(currentLink, logWriter)
	if err := resetLsphpWorkers(ctx, spec.LinuxUser); err != nil {
		return DeployResult{}, err
	}
	if err := RunHooks(ctx, "after_activate", spec.Hooks.AfterActivate, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}

	// Stage 4: Health Check & Purge
	logStep(logWriter, "Stage 4/4: Running health checks and purging old releases...")
	if spec.HealthCheck.Enabled {
		if err := CheckHealth(ctx, spec.Domain, spec.HealthCheck); err != nil {
			if spec.HealthCheck.AutoRollback && previousReleaseDir != "" {
				logStep(logWriter, fmt.Sprintf("Health check failed (%v); rolling back to previous release...", err))
				_ = AtomicSymlink(previousReleaseDir, currentLink, uid, gid)
				_ = resetLsphpWorkers(ctx, spec.LinuxUser)
				return DeployResult{
					Status:        "rolled_back",
					CommitSHA:     meta.SHA,
					CommitMessage: meta.Message,
					CommitAuthor:  meta.Author,
				}, ErrRolledBack
			}
			return DeployResult{}, fmt.Errorf("health check failed: %w", err)
		}
	}

	if err := RunHooks(ctx, "before_purge", spec.Hooks.BeforePurge, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}
	if err := purgeOldReleases(spec.DomainRoot, spec.KeepReleases, releaseDir); err != nil {
		return DeployResult{}, fmt.Errorf("purge old releases: %w", err)
	}
	purged = true
	if err := RunHooks(ctx, "after_purge", spec.Hooks.AfterPurge, spec, releaseDir, meta, logWriter); err != nil {
		return DeployResult{}, err
	}

	logStep(logWriter, fmt.Sprintf("Deployment %s activated successfully.", spec.ReleaseID))

	return DeployResult{
		Status:        "active",
		CommitSHA:     meta.SHA,
		CommitMessage: meta.Message,
		CommitAuthor:  meta.Author,
	}, nil
}

func logStep(w io.Writer, msg string) {
	if w != nil {
		_, _ = fmt.Fprintf(w, "[%s] %s\n", time.Now().UTC().Format("15:04:05"), msg)
	}
}

func ensurePublicHTMLSymlink(domainRoot, webSubpath string, uid, gid int) error {
	publicHTMLPath := filepath.Join(domainRoot, "public_html")
	webTarget := filepath.Join(domainRoot, "current", webSubpath)

	info, err := os.Lstat(publicHTMLPath)
	if err == nil {
		if info.Mode()&os.ModeSymlink == 0 && info.IsDir() {
			backupPath := fmt.Sprintf("%s.simple_bak_%d", publicHTMLPath, time.Now().Unix())
			if err := os.Rename(publicHTMLPath, backupPath); err != nil {
				return fmt.Errorf("backup physical public_html: %w", err)
			}
		}
	} else if !os.IsNotExist(err) {
		return fmt.Errorf("lstat %s: %w", publicHTMLPath, err)
	}

	return AtomicSymlink(webTarget, publicHTMLPath, uid, gid)
}

func restoreContextBestEffort(targetPath string, logWriter io.Writer) {
	if err := RestoreContext(targetPath); err != nil && logWriter != nil {
		_, _ = fmt.Fprintf(logWriter, "[selinux] warning: %v\n", err)
	}
}

func resetLsphpWorkers(ctx context.Context, linuxUser string) error {
	if runtime.GOOS == "windows" {
		return nil
	}

	cmd := exec.CommandContext(ctx, "pkill", "-U", linuxUser, "-f", "lsphp")
	err := cmd.Run()
	if err == nil {
		return nil
	}

	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) && exitErr.ExitCode() == 1 {
		// Exit code 1 means no matching lsphp processes were running.
		return nil
	}

	return fmt.Errorf("reset lsphp workers for %s: %w", linuxUser, err)
}

func purgeOldReleases(domainRoot string, keepReleases int, activeReleaseDir string) error {
	if keepReleases <= 0 {
		return nil
	}

	releasesDir := filepath.Join(domainRoot, "releases")
	entries, err := os.ReadDir(releasesDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}

	var dirs []string
	for _, entry := range entries {
		if entry.IsDir() {
			dirs = append(dirs, entry.Name())
		}
	}

	sort.Strings(dirs)
	if len(dirs) <= keepReleases {
		return nil
	}

	toDelete := dirs[:len(dirs)-keepReleases]
	for _, name := range toDelete {
		candidate := filepath.Join(releasesDir, name)
		if filepath.Clean(candidate) == filepath.Clean(activeReleaseDir) {
			continue
		}
		if err := os.RemoveAll(candidate); err != nil {
			return fmt.Errorf("remove old release %s: %w", candidate, err)
		}
	}

	return nil
}

func lookupUIDGID(username string) (int, int, error) {
	if runtime.GOOS == "windows" {
		return 0, 0, nil
	}

	u, err := user.Lookup(username)
	if err != nil {
		return 0, 0, fmt.Errorf("lookup user %q: %w", username, err)
	}

	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return 0, 0, fmt.Errorf("parse uid %q: %w", u.Uid, err)
	}

	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return 0, 0, fmt.Errorf("parse gid %q: %w", u.Gid, err)
	}

	return uid, gid, nil
}
