//go:build !linux

package deploy

import (
	"context"
	"errors"
	"io"
)

// RunAsTenantUser is only supported on Linux.
func RunAsTenantUser(ctx context.Context, username string, workDir string, env []string, script string, logWriter io.Writer) error {
	return errors.New("RunAsTenantUser is only supported on Linux")
}
