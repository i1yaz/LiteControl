//go:build !linux

package deploy

import (
	"context"
	"errors"
	"io"
	"time"
)

// RunAsTenantUser is only supported on Linux.
func RunAsTenantUser(ctx context.Context, username string, workDir string, env []string, script string, logWriter io.Writer) error {
	return ExecuteAsTenantUser(ctx, TenantExecRequest{
		Username:  username,
		WorkDir:   workDir,
		Env:       env,
		Script:    script,
		LogWriter: logWriter,
	}).Err
}

// ExecuteAsTenantUser is only supported on Linux.
func ExecuteAsTenantUser(ctx context.Context, req TenantExecRequest) TenantExecResult {
	start := time.Now()
	if err := ValidateDomainUser(req.Username); err != nil {
		return TenantExecResult{
			ExitCode: 1,
			Duration: time.Since(start),
			Err:      err,
		}
	}
	return TenantExecResult{
		ExitCode: 1,
		Duration: time.Since(start),
		Err:      errors.New("RunAsTenantUser is only supported on Linux"),
	}
}
