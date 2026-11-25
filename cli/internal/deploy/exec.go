//go:build linux

package deploy

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os/exec"
	"os/user"
	"strconv"
	"syscall"
	"time"
)

// RunAsTenantUser executes script via /bin/bash -c with set -euo pipefail
// under the specified Domain Linux user's UID/GID, streaming stdout and stderr to logWriter.
func RunAsTenantUser(ctx context.Context, username string, workDir string, env []string, script string, logWriter io.Writer) error {
	res := ExecuteAsTenantUser(ctx, TenantExecRequest{
		Username:  username,
		WorkDir:   workDir,
		Env:       env,
		Script:    script,
		LogWriter: logWriter,
	})
	return res.Err
}

// ExecuteAsTenantUser executes a TenantExecRequest under the Domain user with strict
// environment isolation, set -euo pipefail wrapping, and per-execution timeout enforcement.
func ExecuteAsTenantUser(ctx context.Context, req TenantExecRequest) TenantExecResult {
	start := time.Now()

	if err := ValidateDomainUser(req.Username); err != nil {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      err,
		}
	}

	u, err := user.Lookup(req.Username)
	if err != nil {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      fmt.Errorf("lookup user %q: %w", req.Username, err),
		}
	}

	uid, err := strconv.ParseUint(u.Uid, 10, 32)
	if err != nil {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      fmt.Errorf("parse uid %q: %w", u.Uid, err),
		}
	}

	if uid == 0 {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      fmt.Errorf("refusing to execute deployment script as UID 0 (%q)", req.Username),
		}
	}

	gid, err := strconv.ParseUint(u.Gid, 10, 32)
	if err != nil {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      fmt.Errorf("parse gid %q: %w", u.Gid, err),
		}
	}

	execCtx := ctx
	var cancel context.CancelFunc
	if req.Timeout > 0 {
		execCtx, cancel = context.WithTimeout(ctx, req.Timeout)
		defer cancel()
	}

	fullScript := WrapScript(req.Script)
	cmd := exec.CommandContext(execCtx, "/bin/bash", "-c", fullScript)
	cmd.Dir = req.WorkDir
	cmd.Env = BuildTenantEnv(u.Username, u.HomeDir, req.Env)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Credential: &syscall.Credential{
			Uid: uint32(uid),
			Gid: uint32(gid),
		},
		Setpgid: true,
	}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
	cmd.WaitDelay = 2 * time.Second
	cmd.Stdout = req.LogWriter
	cmd.Stderr = req.LogWriter

	runErr := cmd.Run()
	elapsed := time.Since(start)
	if runErr == nil {
		return TenantExecResult{
			ExitCode: 0,
			Duration: elapsed,
		}
	}

	if req.Timeout > 0 && errors.Is(execCtx.Err(), context.DeadlineExceeded) {
		return TenantExecResult{
			ExitCode: TimeoutExitCode,
			Duration: elapsed,
			TimedOut: true,
			Err:      fmt.Errorf("execution timed out after %s as %s in %s: %w", req.Timeout, req.Username, req.WorkDir, execCtx.Err()),
		}
	}

	exitCode := 1
	var exitErr *exec.ExitError
	if errors.As(runErr, &exitErr) {
		exitCode = exitErr.ExitCode()
	}

	return TenantExecResult{
		ExitCode: exitCode,
		Duration: elapsed,
		Err:      fmt.Errorf("run as %s in %s (exit code %d): %w", req.Username, req.WorkDir, exitCode, runErr),
	}
}
