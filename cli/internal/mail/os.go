package mail

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

// CommandRunner executes OS commands. Abstracted for deterministic testing.
type CommandRunner interface {
	Run(ctx context.Context, name string, args ...string) (string, error)
	LookPath(file string) (string, error)
}

// ExecRunner is the default CommandRunner backed by os/exec.
type ExecRunner struct{}

// Run executes a command and returns its combined stdout/stderr output.
func (ExecRunner) Run(ctx context.Context, name string, args ...string) (string, error) {
	cmd := exec.CommandContext(ctx, name, args...)
	if os.Getenv("DEBIAN_FRONTEND") == "" {
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
	}
	var buf bytes.Buffer
	cmd.Stdout = &buf
	cmd.Stderr = &buf
	err := cmd.Run()
	if err != nil {
		return buf.String(), fmt.Errorf("%s %s: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(buf.String()))
	}
	return buf.String(), nil
}

// LookPath resolves an executable in PATH.
func (ExecRunner) LookPath(file string) (string, error) {
	return exec.LookPath(file)
}

// OSFamily identifies the Linux distribution family.
type OSFamily string

const (
	OSFamilyDebian OSFamily = "debian"
	OSFamilyRHEL   OSFamily = "rhel"
)

// OSInfo describes the detected Linux distribution.
type OSInfo struct {
	ID         string
	VersionID  string
	Codename   string
	Family     OSFamily
	MajorVer   int
	PrettyName string
}

// DetectOS reads /etc/os-release (or rootDir + /etc/os-release) and validates support.
func DetectOS(rootDir string) (OSInfo, error) {
	data, err := os.ReadFile(filepath.Join(rootDir, "etc", "os-release"))
	if err != nil {
		return OSInfo{}, fmt.Errorf("read /etc/os-release: %w", err)
	}
	return ParseOSRelease(string(data))
}

// ParseOSRelease parses /etc/os-release content and validates supported distributions:
// Debian 12/13, Ubuntu 22.04/24.04/26.04, Rocky/AlmaLinux 8/9/10.
func ParseOSRelease(content string) (OSInfo, error) {
	fields := make(map[string]string)
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		v = strings.Trim(v, `"'`)
		fields[k] = v
	}

	id := strings.ToLower(fields["ID"])
	versionID := fields["VERSION_ID"]
	codename := fields["VERSION_CODENAME"]
	pretty := fields["PRETTY_NAME"]

	majorStr := versionID
	if idx := strings.Index(majorStr, "."); idx != -1 {
		majorStr = majorStr[:idx]
	}
	var major int
	if majorStr != "" {
		_, _ = fmt.Sscanf(majorStr, "%d", &major)
	}

	info := OSInfo{
		ID:         id,
		VersionID:  versionID,
		Codename:   codename,
		MajorVer:   major,
		PrettyName: pretty,
	}

	switch id {
	case "debian":
		if major != 12 && major != 13 {
			return OSInfo{}, fmt.Errorf("unsupported Debian version %q (supported: 12, 13)", versionID)
		}
		info.Family = OSFamilyDebian
	case "ubuntu":
		if versionID != "22.04" && versionID != "24.04" && versionID != "26.04" {
			return OSInfo{}, fmt.Errorf("unsupported Ubuntu version %q (supported: 22.04, 24.04, 26.04)", versionID)
		}
		info.Family = OSFamilyDebian
	case "rocky", "almalinux":
		if major < 8 || major > 10 {
			return OSInfo{}, fmt.Errorf("unsupported %s version %q (supported: 8, 9, 10)", id, versionID)
		}
		info.Family = OSFamilyRHEL
	default:
		return OSInfo{}, fmt.Errorf("unsupported OS %q (supported: Debian 12/13, Ubuntu 22.04/24.04/26.04, Rocky/AlmaLinux 8/9/10)", id)
	}

	return info, nil
}

// IsSelinuxEnforcing reports whether SELinux is currently enforcing.
func IsSelinuxEnforcing(rootDir string, runner CommandRunner) bool {
	if rootDir == "" && runtime.GOOS != "linux" {
		return false
	}

	enforcePath := filepath.Join(rootDir, "sys", "fs", "selinux", "enforce")
	if data, err := os.ReadFile(enforcePath); err == nil {
		return strings.TrimSpace(string(data)) == "1"
	}

	if runner != nil && runtime.GOOS == "linux" {
		if out, err := runner.Run(context.Background(), "getenforce"); err == nil {
			return strings.TrimSpace(out) == "Enforcing"
		}
	}

	return false
}

// RestoreSELinuxContexts runs `restorecon -RF` on managed mail paths when SELinux is enforcing.
func RestoreSELinuxContexts(ctx context.Context, rootDir string, runner CommandRunner, paths []string) error {
	if !IsSelinuxEnforcing(rootDir, runner) {
		return nil
	}

	for _, p := range paths {
		if p == "" {
			continue
		}
		if _, err := runner.Run(ctx, "restorecon", "-RF", p); err != nil {
			return fmt.Errorf("restorecon -RF %s: %w", p, err)
		}
	}
	return nil
}
