package deploy

import (
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
)

const selinuxEnforcePath = "/sys/fs/selinux/enforce"

// IsSelinuxEnforcing reports whether SELinux is currently enabled and in enforcing mode.
// Returns false on non-Linux systems, when SELinux is disabled/permissive, or when unavailable (e.g. Ubuntu).
func IsSelinuxEnforcing() bool {
	if runtime.GOOS != "linux" {
		return false
	}

	return isSelinuxEnforcingFrom(selinuxEnforcePath, func() (string, error) {
		out, err := exec.Command("getenforce").Output()
		if err != nil {
			return "", err
		}
		return string(out), nil
	})
}

func isSelinuxEnforcingFrom(enforceFile string, getenforce func() (string, error)) bool {
	if data, err := os.ReadFile(enforceFile); err == nil {
		return strings.TrimSpace(string(data)) == "1"
	}

	if getenforce != nil {
		if out, err := getenforce(); err == nil {
			return strings.TrimSpace(out) == "Enforcing"
		}
	}

	return false
}

// RestoreContext runs `restorecon -R <targetPath>` when SELinux is enforcing.
func RestoreContext(targetPath string) error {
	if targetPath == "" || !IsSelinuxEnforcing() {
		return nil
	}

	out, err := exec.Command("restorecon", "-R", targetPath).CombinedOutput()
	if err != nil {
		return fmt.Errorf("restorecon -R %s: %w (%s)", targetPath, err, strings.TrimSpace(string(out)))
	}

	return nil
}
