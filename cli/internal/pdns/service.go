package pdns

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// CommandRunner abstracts external OS commands for deterministic testing.
type CommandRunner interface {
	Run(ctx context.Context, name string, args ...string) (string, error)
}

// ExecRunner executes real OS binaries via os/exec.
type ExecRunner struct{}

// Run executes `name args...` and returns combined stdout/stderr.
func (ExecRunner) Run(ctx context.Context, name string, args ...string) (string, error) {
	cmd := exec.CommandContext(ctx, name, args...)
	out, err := cmd.CombinedOutput()
	return string(out), err
}

// StatusResult is the JSON payload returned by `lc-cli pdns status`.
type StatusResult struct {
	DistroFamily   OSFamily    `json:"distro_family"`
	ConfigPath     string      `json:"config_path"`
	DropInDir      string      `json:"drop_in_dir"`
	DropInPath     string      `json:"drop_in_path"`
	ServiceName    string      `json:"service_name"`
	SystemdActive  bool        `json:"systemd_active"`
	SystemdState   string      `json:"systemd_state"`
	DropInPresent  bool        `json:"drop_in_present"`
	IncludeEnabled bool        `json:"include_dir_enabled"`
	Port53         Port53Check `json:"port_53"`
}

// ApplyResult is the JSON payload returned by `lc-cli pdns apply-config`.
type ApplyResult struct {
	DistroFamily   OSFamily `json:"distro_family"`
	ConfigPath     string   `json:"config_path"`
	DropInPath     string   `json:"drop_in_path"`
	IncludeUpdated bool     `json:"include_dir_updated"`
	Validated      bool     `json:"validated"`
	ServiceState   string   `json:"service_state"`
}

// Manager coordinates PowerDNS OS config files, validation, and systemd control.
type Manager struct {
	RootPrefix    string
	OSReleasePath string
	Runner        CommandRunner
	SkipChown     bool
}

// NewManager creates a Manager using real OS paths and command execution.
func NewManager() *Manager {
	return &Manager{
		Runner: ExecRunner{},
	}
}

// Status inspects OS distro paths, systemd active state, and port 53 socket ownership.
func (m *Manager) Status(ctx context.Context) (StatusResult, error) {
	paths, err := ResolvePaths(m.OSReleasePath, m.RootPrefix)
	if err != nil {
		return StatusResult{}, err
	}

	runner := m.runner()
	stateOut, activeErr := runner.Run(ctx, "systemctl", "is-active", paths.ServiceName)
	systemdState := strings.TrimSpace(stateOut)
	if systemdState == "" {
		if activeErr != nil {
			systemdState = "inactive"
		} else {
			systemdState = "active"
		}
	}
	systemdActive := activeErr == nil && systemdState == "active"

	_, dropInErr := os.Stat(paths.DropInPath)
	dropInPresent := dropInErr == nil

	includeEnabled := false
	if mainBytes, readErr := os.ReadFile(paths.MainConfigPath); readErr == nil {
		canonicalDropInDir := canonicalTargetDropInDir(paths.DropInDir, m.RootPrefix)
		_, modified := EnsureIncludeDir(string(mainBytes), canonicalDropInDir)
		includeEnabled = !modified
	}

	ssOut, _ := runner.Run(ctx, "ss", "-tulnp")
	port53 := ParsePort53Listeners(ssOut)

	return StatusResult{
		DistroFamily:   paths.OSFamily,
		ConfigPath:     paths.MainConfigPath,
		DropInDir:      paths.DropInDir,
		DropInPath:     paths.DropInPath,
		ServiceName:    paths.ServiceName,
		SystemdActive:  systemdActive,
		SystemdState:   systemdState,
		DropInPresent:  dropInPresent,
		IncludeEnabled: includeEnabled,
		Port53:         port53,
	}, nil
}

// ApplyConfig writes the drop-in config atomically, validates with `pdns_server --config-check`,
// checks for port 53 conflicts, restarts `pdns`, and rolls back on any validation or restart failure.
func (m *Manager) ApplyConfig(ctx context.Context, payload ConfigPayload) (ApplyResult, error) {
	renderedDropIn, err := SerializeDropIn(payload)
	if err != nil {
		return ApplyResult{}, fmt.Errorf("invalid PowerDNS configuration payload: %w", err)
	}

	paths, err := ResolvePaths(m.OSReleasePath, m.RootPrefix)
	if err != nil {
		return ApplyResult{}, err
	}

	runner := m.runner()

	// Pre-flight 1: Check for port 53 conflicts before touching or restarting pdns
	if ssOut, ssErr := runner.Run(ctx, "ss", "-tulnp"); ssErr == nil {
		p53 := ParsePort53Listeners(ssOut)
		if p53.Conflict {
			return ApplyResult{}, fmt.Errorf("port 53 conflict detected: %s", p53.ConflictInfo)
		}
	}

	// Ensure main pdns.conf exists and has include-dir pointing to pdns.d
	mainOriginal, err := os.ReadFile(paths.MainConfigPath)
	if err != nil {
		return ApplyResult{}, fmt.Errorf("read main PowerDNS config %s: %w", paths.MainConfigPath, err)
	}

	if err := os.MkdirAll(paths.DropInDir, 0o750); err != nil {
		return ApplyResult{}, fmt.Errorf("create drop-in directory %s: %w", paths.DropInDir, err)
	}

	canonicalDropInDir := canonicalTargetDropInDir(paths.DropInDir, m.RootPrefix)
	updatedMain, includeUpdated := EnsureIncludeDir(string(mainOriginal), canonicalDropInDir)

	// Snapshot prior drop-in file if it exists
	priorDropIn, priorDropInErr := os.ReadFile(paths.DropInPath)
	hadPriorDropIn := priorDropInErr == nil

	// Write temp drop-in file with 0640 root:pdns permissions
	tmpDropIn := fmt.Sprintf("%s.tmp.%d", paths.DropInPath, time.Now().UnixNano())
	if err := writeSecureConfigFile(tmpDropIn, []byte(renderedDropIn), 0o640, m.SkipChown); err != nil {
		return ApplyResult{}, fmt.Errorf("write temporary drop-in %s: %w", tmpDropIn, err)
	}
	defer func() {
		_ = os.Remove(tmpDropIn)
	}()

	// If main pdns.conf needs include-dir updated, write it via atomic swap before running --config-check
	if includeUpdated {
		tmpMain := fmt.Sprintf("%s.tmp.%d", paths.MainConfigPath, time.Now().UnixNano())
		if err := writeSecureConfigFile(tmpMain, []byte(updatedMain), 0o640, m.SkipChown); err != nil {
			return ApplyResult{}, fmt.Errorf("write temporary main config %s: %w", tmpMain, err)
		}
		if err := os.Rename(tmpMain, paths.MainConfigPath); err != nil {
			_ = os.Remove(tmpMain)
			return ApplyResult{}, fmt.Errorf("update include-dir in %s: %w", paths.MainConfigPath, err)
		}
	}

	rollbackFiles := func() {
		if hadPriorDropIn {
			_ = writeSecureConfigFile(paths.DropInPath, priorDropIn, 0o640, m.SkipChown)
		} else {
			_ = os.Remove(paths.DropInPath)
		}
		if includeUpdated {
			_ = writeSecureConfigFile(paths.MainConfigPath, mainOriginal, 0o640, m.SkipChown)
		}
	}

	// Atomically move new drop-in into place so `pdns_server --config-check` validates the exact include-dir state
	if err := os.Rename(tmpDropIn, paths.DropInPath); err != nil {
		if includeUpdated {
			_ = writeSecureConfigFile(paths.MainConfigPath, mainOriginal, 0o640, m.SkipChown)
		}
		return ApplyResult{}, fmt.Errorf("atomic swap drop-in %s: %w", paths.DropInPath, err)
	}

	// Validate configuration syntax with `pdns_server --config-check`
	checkOut, checkErr := runner.Run(ctx, "pdns_server", "--config-dir="+filepath.Dir(paths.MainConfigPath), "--config-check")
	if checkErr != nil {
		rollbackFiles()
		return ApplyResult{}, fmt.Errorf("pdns_server --config-check failed (rolled back): %w (%s)", checkErr, strings.TrimSpace(checkOut))
	}

	// Restart pdns service and verify it comes up active
	if restartOut, restartErr := runner.Run(ctx, "systemctl", "restart", paths.ServiceName); restartErr != nil {
		rollbackFiles()
		_, _ = runner.Run(ctx, "systemctl", "restart", paths.ServiceName)
		return ApplyResult{}, fmt.Errorf("systemctl restart %s failed (rolled back): %w (%s)", paths.ServiceName, restartErr, strings.TrimSpace(restartOut))
	}

	stateOut, activeErr := runner.Run(ctx, "systemctl", "is-active", paths.ServiceName)
	serviceState := strings.TrimSpace(stateOut)
	if activeErr != nil || (serviceState != "" && serviceState != "active") {
		rollbackFiles()
		_, _ = runner.Run(ctx, "systemctl", "restart", paths.ServiceName)
		if activeErr != nil {
			return ApplyResult{}, fmt.Errorf("pdns service failed post-restart active check (rolled back): %w (%s)", activeErr, serviceState)
		}
		return ApplyResult{}, fmt.Errorf("pdns service is %q after restart (rolled back)", serviceState)
	}

	if serviceState == "" {
		serviceState = "active"
	}

	return ApplyResult{
		DistroFamily:   paths.OSFamily,
		ConfigPath:     paths.MainConfigPath,
		DropInPath:     paths.DropInPath,
		IncludeUpdated: includeUpdated,
		Validated:      true,
		ServiceState:   serviceState,
	}, nil
}

func (m *Manager) runner() CommandRunner {
	if m.Runner != nil {
		return m.Runner
	}
	return ExecRunner{}
}

func canonicalTargetDropInDir(dropInDir string, rootPrefix string) string {
	if rootPrefix == "" {
		return filepath.ToSlash(dropInDir)
	}
	rel, err := filepath.Rel(rootPrefix, filepath.FromSlash(dropInDir))
	if err != nil {
		return filepath.ToSlash(dropInDir)
	}
	return "/" + strings.TrimPrefix(filepath.ToSlash(rel), "/")
}

func writeSecureConfigFile(path string, content []byte, perm os.FileMode, skipChown bool) error {
	if err := os.WriteFile(path, content, perm); err != nil {
		return err
	}
	if err := os.Chmod(path, perm); err != nil {
		_ = os.Remove(path)
		return err
	}
	if runtime.GOOS != "windows" && !skipChown {
		if gid, ok := lookupPdnsGroupID(); ok {
			if err := os.Chown(path, 0, gid); err != nil && !errors.Is(err, os.ErrPermission) {
				_ = os.Remove(path)
				return fmt.Errorf("chown root:pdns %s: %w", path, err)
			}
		}
	}
	return nil
}

func lookupPdnsGroupID() (int, bool) {
	g, err := user.LookupGroup("pdns")
	if err != nil {
		return -1, false
	}
	gid, err := strconv.Atoi(g.Gid)
	if err != nil {
		return -1, false
	}
	return gid, true
}
