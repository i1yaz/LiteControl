package deploy

import (
	"context"
	"fmt"
	"io"
	"path/filepath"
	"strings"
)

// InterpolateHook replaces {{variable}} tokens in a hook script with deployment values.
func InterpolateHook(script string, spec Payload, releaseDir string, commitMeta CommitMeta) string {
	replacer := strings.NewReplacer(
		"{{release}}", releaseDir,
		"{{project}}", spec.DomainRoot,
		"{{shared}}", filepath.Join(spec.DomainRoot, "shared"),
		"{{php}}", spec.PhpBinary,
		"{{composer}}", spec.ComposerCmd,
		"{{branch}}", spec.Repo.Branch,
		"{{sha}}", commitMeta.SHA,
	)

	return replacer.Replace(script)
}

// RunHooks executes ordered hook scripts in releaseDir as spec.LinuxUser.
// Execution halts immediately on the first non-zero exit code.
func RunHooks(
	ctx context.Context,
	stageName string,
	hooks []string,
	spec Payload,
	releaseDir string,
	commitMeta CommitMeta,
	logWriter io.Writer,
) error {
	for i, raw := range hooks {
		script := InterpolateHook(raw, spec, releaseDir, commitMeta)
		if logWriter != nil {
			_, _ = fmt.Fprintf(logWriter, "[hook:%s#%d] %s\n", stageName, i+1, script)
		}
		if err := RunAsTenantUser(ctx, spec.LinuxUser, releaseDir, nil, script, logWriter); err != nil {
			return fmt.Errorf("hook %s[%d] failed: %w", stageName, i, err)
		}
	}

	return nil
}
