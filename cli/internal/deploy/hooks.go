package deploy

import (
	"context"
	"fmt"
	"io"
	"path/filepath"
	"regexp"
	"strings"
)

const (
	SlotBeforeClone   = "before_clone"
	SlotAfterClone    = "after_clone"
	SlotAfterInstall  = "after_install"
	SlotAfterActivate = "after_activate"
	SlotAfterPurge    = "after_purge"

	StageClone    = "clone"
	StageInstall  = "install"
	StageActivate = "activate"
	StagePurge    = "purge"
)

var (
	releasePlaceholderPattern = regexp.MustCompile(`(?i)\{\{\s*release\s*\}\}`)
	unsafeShellChars          = ";|&$`<>\"\n\r\x00"
)

// ContainsReleasePlaceholder returns true if script references {{release}} (case-insensitive, whitespace-tolerant).
func ContainsReleasePlaceholder(script string) bool {
	return releasePlaceholderPattern.MatchString(script)
}

// HookWorkingDir returns the strict working directory for a given slot/stage:
// before_clone executes in {{project}} (spec.DomainRoot); Stage 2 and all other slots execute in {{release}}.
func HookWorkingDir(slot string, spec Payload, releaseDir string) string {
	if slot == SlotBeforeClone {
		return spec.DomainRoot
	}
	return releaseDir
}

// InterpolateHook replaces allowlisted {{variable}} tokens in a hook script with deployment values.
// Uses single-pass literal replacement (no eval, no double-expansion).
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

	return replacer.Replace(NormalizeScript(script))
}

// ResolveHookScript normalizes line endings, enforces slot security invariants
// (rejecting {{release}} in before_clone and unsafe shell metacharacters in dynamic values),
// and performs single-pass allowlisted placeholder substitution.
func ResolveHookScript(slot string, rawScript string, spec Payload, releaseDir string, commitMeta CommitMeta) (string, error) {
	normalized := NormalizeScript(rawScript)

	if slot == SlotBeforeClone && ContainsReleasePlaceholder(normalized) {
		return "", fmt.Errorf("hook in slot %q cannot reference {{release}} because the release directory does not exist before cloning", slot)
	}

	if strings.Contains(normalized, "{{branch}}") && strings.ContainsAny(spec.Repo.Branch, unsafeShellChars) {
		return "", fmt.Errorf("refusing to interpolate unsafe characters in {{branch}} value")
	}

	if strings.Contains(normalized, "{{sha}}") && strings.ContainsAny(commitMeta.SHA, unsafeShellChars) {
		return "", fmt.Errorf("refusing to interpolate unsafe characters in {{sha}} value")
	}

	pairs := make([]string, 0, 14)
	if slot != SlotBeforeClone {
		pairs = append(pairs, "{{release}}", releaseDir)
	}
	pairs = append(pairs,
		"{{project}}", spec.DomainRoot,
		"{{shared}}", filepath.Join(spec.DomainRoot, "shared"),
		"{{php}}", spec.PhpBinary,
		"{{composer}}", spec.ComposerCmd,
		"{{branch}}", spec.Repo.Branch,
		"{{sha}}", commitMeta.SHA,
	)

	return strings.NewReplacer(pairs...).Replace(normalized), nil
}

// RunHooks executes ordered hook scripts for a slot using the default Linux tenant executor.
// Execution halts immediately on the first error, non-zero exit code, or timeout.
func RunHooks(
	ctx context.Context,
	slot string,
	stage string,
	hooks []HookSpec,
	spec Payload,
	releaseDir string,
	commitMeta CommitMeta,
	logWriter io.Writer,
) error {
	return runSlotHooks(ctx, slot, stage, hooks, spec, releaseDir, commitMeta, ExecuteAsTenantUser, logWriter)
}

func runSlotHooks(
	ctx context.Context,
	slot string,
	stage string,
	hooks []HookSpec,
	spec Payload,
	releaseDir string,
	commitMeta CommitMeta,
	executor TenantExecutor,
	logWriter io.Writer,
) error {
	cwd := HookWorkingDir(slot, spec, releaseDir)
	timeout := spec.HookTimeout()

	for i, hook := range hooks {
		hookName := strings.TrimSpace(hook.Name)
		if hookName == "" {
			hookName = fmt.Sprintf("%s#%d", slot, i+1)
		}

		resolvedScript, err := ResolveHookScript(slot, hook.Script, spec, releaseDir, commitMeta)
		if err != nil {
			EmitStructuredLog(logWriter, ExecutionLogEntry{
				Event:        "hook_failed",
				Status:       "failed",
				SiteID:       spec.SiteID,
				DeploymentID: spec.DeploymentID,
				Stage:        stage,
				Slot:         slot,
				HookID:       hook.ID,
				HookName:     hookName,
				User:         spec.LinuxUser,
				Cwd:          cwd,
				ExitCode:     1,
				DurationMs:   0,
				Error:        err.Error(),
			})
			return fmt.Errorf("hook %s[%d] (%s) failed: %w", slot, i, hookName, err)
		}

		res := executor(ctx, TenantExecRequest{
			Username:  spec.LinuxUser,
			WorkDir:   cwd,
			Script:    resolvedScript,
			Timeout:   timeout,
			LogWriter: logWriter,
		})

		entry := ExecutionLogEntry{
			Event:        "hook_completed",
			Status:       "ok",
			SiteID:       spec.SiteID,
			DeploymentID: spec.DeploymentID,
			Stage:        stage,
			Slot:         slot,
			HookID:       hook.ID,
			HookName:     hookName,
			User:         spec.LinuxUser,
			Cwd:          cwd,
			ExitCode:     res.ExitCode,
			DurationMs:   res.Duration.Milliseconds(),
		}

		if res.Err != nil || res.ExitCode != 0 || res.TimedOut {
			if res.TimedOut {
				entry.Event = "hook_timeout"
				entry.Status = "timeout"
				if entry.ExitCode == 0 {
					entry.ExitCode = TimeoutExitCode
				}
			} else {
				entry.Event = "hook_failed"
				entry.Status = "failed"
				if entry.ExitCode == 0 {
					entry.ExitCode = 1
				}
			}
			if res.Err != nil {
				entry.Error = res.Err.Error()
			} else {
				entry.Error = fmt.Sprintf("exited with status %d", entry.ExitCode)
			}
			EmitStructuredLog(logWriter, entry)
			return fmt.Errorf("hook %s[%d] (%s) failed (exit_code=%d): %s", slot, i, hookName, entry.ExitCode, entry.Error)
		}

		EmitStructuredLog(logWriter, entry)
	}

	return nil
}

func runInstallStage(
	ctx context.Context,
	spec Payload,
	releaseDir string,
	commitMeta CommitMeta,
	executor TenantExecutor,
	logWriter io.Writer,
) error {
	cwd := HookWorkingDir(StageInstall, spec, releaseDir)

	rawScript := ""
	if spec.InstallScript != nil {
		rawScript = NormalizeScript(*spec.InstallScript)
	}

	if strings.TrimSpace(rawScript) == "" {
		logStep(logWriter, "Stage 2/4: Install Dependencies — Skipped (no install script configured).")
		EmitStructuredLog(logWriter, ExecutionLogEntry{
			Event:        "stage_skipped",
			Status:       "skipped",
			SiteID:       spec.SiteID,
			DeploymentID: spec.DeploymentID,
			Stage:        StageInstall,
			Slot:         StageInstall,
			HookID:       0,
			HookName:     "Install Dependencies",
			User:         spec.LinuxUser,
			Cwd:          cwd,
			ExitCode:     0,
			DurationMs:   0,
		})
		return nil
	}

	logStep(logWriter, "Stage 2/4: Running Install Dependencies script...")

	resolvedScript, err := ResolveHookScript(StageInstall, rawScript, spec, releaseDir, commitMeta)
	if err != nil {
		EmitStructuredLog(logWriter, ExecutionLogEntry{
			Event:        "stage_failed",
			Status:       "failed",
			SiteID:       spec.SiteID,
			DeploymentID: spec.DeploymentID,
			Stage:        StageInstall,
			Slot:         StageInstall,
			HookID:       0,
			HookName:     "Install Dependencies",
			User:         spec.LinuxUser,
			Cwd:          cwd,
			ExitCode:     1,
			DurationMs:   0,
			Error:        err.Error(),
		})
		return fmt.Errorf("stage install failed: %w", err)
	}

	res := executor(ctx, TenantExecRequest{
		Username:  spec.LinuxUser,
		WorkDir:   cwd,
		Script:    resolvedScript,
		Timeout:   spec.HookTimeout(),
		LogWriter: logWriter,
	})

	entry := ExecutionLogEntry{
		Event:        "stage_completed",
		Status:       "ok",
		SiteID:       spec.SiteID,
		DeploymentID: spec.DeploymentID,
		Stage:        StageInstall,
		Slot:         StageInstall,
		HookID:       0,
		HookName:     "Install Dependencies",
		User:         spec.LinuxUser,
		Cwd:          cwd,
		ExitCode:     res.ExitCode,
		DurationMs:   res.Duration.Milliseconds(),
	}

	if res.Err != nil || res.ExitCode != 0 || res.TimedOut {
		if res.TimedOut {
			entry.Event = "stage_timeout"
			entry.Status = "timeout"
			if entry.ExitCode == 0 {
				entry.ExitCode = TimeoutExitCode
			}
		} else {
			entry.Event = "stage_failed"
			entry.Status = "failed"
			if entry.ExitCode == 0 {
				entry.ExitCode = 1
			}
		}
		if res.Err != nil {
			entry.Error = res.Err.Error()
		} else {
			entry.Error = fmt.Sprintf("exited with status %d", entry.ExitCode)
		}
		EmitStructuredLog(logWriter, entry)
		return fmt.Errorf("stage install failed (exit_code=%d): %s", entry.ExitCode, entry.Error)
	}

	EmitStructuredLog(logWriter, entry)
	return nil
}
