package deploy

import (
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"
)

const (
	// DefaultTenantPath is the explicit PATH assigned to all Domain-user hook executions.
	DefaultTenantPath = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

	// TimeoutExitCode is the standard exit code recorded when a hook exceeds its execution timeout.
	TimeoutExitCode = 124
)

// TenantExecRequest describes a single script execution under the isolated Domain user.
type TenantExecRequest struct {
	Username  string
	WorkDir   string
	Env       []string
	Script    string
	Timeout   time.Duration
	LogWriter io.Writer
}

// TenantExecResult captures the outcome of a Domain-user script execution.
type TenantExecResult struct {
	ExitCode int
	Duration time.Duration
	TimedOut bool
	Err      error
}

// TenantExecutor executes a script under the Domain user.
type TenantExecutor func(ctx context.Context, req TenantExecRequest) TenantExecResult

// NormalizeScript defensively normalizes CRLF and CR line endings to LF.
func NormalizeScript(script string) string {
	s := strings.ReplaceAll(script, "\r\n", "\n")
	return strings.ReplaceAll(s, "\r", "\n")
}

// WrapScript normalizes line endings and prepends strict bash safety flags (set -euo pipefail).
func WrapScript(script string) string {
	return "set -euo pipefail\n" + NormalizeScript(script)
}

// ValidateDomainUser ensures hooks and Stage 2 never run as an empty or root user.
func ValidateDomainUser(username string) error {
	trimmed := strings.TrimSpace(username)
	if trimmed == "" {
		return errors.New("domain user is required for script execution")
	}
	if strings.EqualFold(trimmed, "root") {
		return fmt.Errorf("refusing to execute deployment script as privileged user %q", username)
	}
	return nil
}

// BuildTenantEnv constructs the explicit environment with HOME, USER, and PATH.
func BuildTenantEnv(username, homeDir string, extra []string) []string {
	base := []string{
		"HOME=" + homeDir,
		"USER=" + username,
		"PATH=" + DefaultTenantPath,
	}
	if len(extra) == 0 {
		return base
	}
	return append(base, extra...)
}
