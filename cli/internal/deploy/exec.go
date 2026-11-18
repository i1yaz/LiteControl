//go:build linux

package deploy

import (
	"context"
	"fmt"
	"io"
	"os/exec"
	"os/user"
	"strconv"
	"syscall"
)

// RunAsTenantUser executes script via /bin/bash -c with set -eo pipefail
// under the specified Linux user's UID/GID, streaming stdout and stderr to logWriter.
func RunAsTenantUser(ctx context.Context, username string, workDir string, env []string, script string, logWriter io.Writer) error {
	u, err := user.Lookup(username)
	if err != nil {
		return fmt.Errorf("lookup user %q: %w", username, err)
	}

	uid, err := strconv.ParseUint(u.Uid, 10, 32)
	if err != nil {
		return fmt.Errorf("parse uid %q: %w", u.Uid, err)
	}

	gid, err := strconv.ParseUint(u.Gid, 10, 32)
	if err != nil {
		return fmt.Errorf("parse gid %q: %w", u.Gid, err)
	}

	fullScript := "set -eo pipefail\n" + script
	cmd := exec.CommandContext(ctx, "/bin/bash", "-c", fullScript)
	cmd.Dir = workDir
	cmd.Env = append([]string{
		"HOME=" + u.HomeDir,
		"USER=" + u.Username,
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	}, env...)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Credential: &syscall.Credential{
			Uid: uint32(uid),
			Gid: uint32(gid),
		},
	}
	cmd.Stdout = logWriter
	cmd.Stderr = logWriter

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("run as %s in %s: %w", username, workDir, err)
	}

	return nil
}
