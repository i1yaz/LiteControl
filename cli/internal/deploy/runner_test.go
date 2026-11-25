package deploy

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func strPtr(s string) *string {
	return &s
}

func writeTestSpec(t *testing.T, domainRoot string, spec Payload) string {
	t.Helper()
	raw, err := json.Marshal(spec)
	if err != nil {
		t.Fatalf("marshal spec: %v", err)
	}
	specPath := filepath.Join(domainRoot, "spec.json")
	if err := os.WriteFile(specPath, raw, 0o600); err != nil {
		t.Fatalf("write spec: %v", err)
	}
	return specPath
}

func parseStructuredLogs(t *testing.T, raw string) []ExecutionLogEntry {
	t.Helper()
	var entries []ExecutionLogEntry
	scanner := bufio.NewScanner(strings.NewReader(raw))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if !strings.HasPrefix(line, "{") {
			continue
		}
		var entry ExecutionLogEntry
		if err := json.Unmarshal([]byte(line), &entry); err == nil && entry.Event != "" {
			entries = append(entries, entry)
		}
	}
	return entries
}

func TestPurgeOldReleases_KeepsNewestAndActive(t *testing.T) {
	domainRoot := t.TempDir()
	releasesDir := filepath.Join(domainRoot, "releases")

	names := []string{
		"20261119_010000_aaa",
		"20261119_020000_bbb",
		"20261119_030000_ccc",
		"20261119_040000_ddd",
	}
	for _, n := range names {
		if err := os.MkdirAll(filepath.Join(releasesDir, n), 0o755); err != nil {
			t.Fatalf("mkdir %s: %v", n, err)
		}
	}

	active := filepath.Join(releasesDir, "20261119_010000_aaa")
	if err := purgeOldReleases(domainRoot, 2, active); err != nil {
		t.Fatalf("purgeOldReleases() error = %v", err)
	}

	if _, err := os.Stat(filepath.Join(releasesDir, "20261119_020000_bbb")); !os.IsNotExist(err) {
		t.Fatalf("expected 20261119_020000_bbb to be deleted")
	}
	for _, keep := range []string{"20261119_010000_aaa", "20261119_030000_ccc", "20261119_040000_ddd"} {
		if _, err := os.Stat(filepath.Join(releasesDir, keep)); err != nil {
			t.Fatalf("expected %s to be kept, got err: %v", keep, err)
		}
	}
}

func TestRunDeploy_PurgesOldReleasesOnFailure(t *testing.T) {
	domainRoot := t.TempDir()
	releasesDir := filepath.Join(domainRoot, "releases")

	existing := []string{
		"20261119_010000_aaa",
		"20261119_020000_bbb",
		"20261119_030000_ccc",
	}
	for _, n := range existing {
		if err := os.MkdirAll(filepath.Join(releasesDir, n), 0o755); err != nil {
			t.Fatalf("mkdir %s: %v", n, err)
		}
	}

	spec := Payload{
		SiteID:       10,
		DeploymentID: 99,
		Domain:       "example.com",
		LinuxUser:    "client1",
		DomainRoot:   domainRoot,
		ReleaseID:    "20261119_040000_ddd",
		KeepReleases: 2,
		Repo: RepoConfig{
			URL:    "file:///nonexistent/repo.git",
			Branch: "main",
			Driver: "unsupported_driver_to_fail_fast",
		},
	}
	specPath := writeTestSpec(t, domainRoot, spec)

	var logBuf bytes.Buffer
	_, runErr := RunDeploy(context.Background(), specPath, &logBuf)
	if runErr == nil {
		t.Fatalf("expected RunDeploy to fail, got nil")
	}

	if _, err := os.Stat(filepath.Join(releasesDir, "20261119_010000_aaa")); !os.IsNotExist(err) {
		t.Fatalf("expected oldest release 20261119_010000_aaa to be purged on failure")
	}
	for _, keep := range []string{"20261119_020000_bbb", "20261119_030000_ccc"} {
		if _, err := os.Stat(filepath.Join(releasesDir, keep)); err != nil {
			t.Fatalf("expected %s to be kept, got err: %v", keep, err)
		}
	}
}

func TestRunDeploy_ExecutesImmutable9PhaseSequenceWithWorkingDirsAndDomainUser(t *testing.T) {
	domainRoot := t.TempDir()
	releaseID := "20261126_010000_abc"
	releaseDir := filepath.Join(domainRoot, "releases", releaseID)

	spec := Payload{
		SiteID:             42,
		DeploymentID:       108,
		ReleaseID:          releaseID,
		LinuxUser:          "tenant_alice",
		Domain:             "alice.example.com",
		DomainRoot:         domainRoot,
		WebSubpath:         "public",
		PhpBinary:          "/usr/local/lsws/lsphp83/bin/php",
		ComposerCmd:        "/usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer",
		InstallScript:      strPtr("{{composer}} install --no-dev\r\n{{php}} {{release}}/artisan package:discover"),
		HookTimeoutSeconds: 45,
		KeepReleases:       3,
		Repo: RepoConfig{
			Driver: "shallow_git",
			URL:    "git@github.com:org/repo.git",
			Branch: "main",
		},
		Hooks: HooksConfig{
			BeforeClone: []HookSpec{
				{ID: 1, Name: "Pre-flight 1", Script: "echo before_clone_1 {{project}}"},
				{ID: 2, Name: "Pre-flight 2", Script: "echo before_clone_2 {{shared}}"},
			},
			AfterClone: []HookSpec{
				{ID: 3, Name: "After Clone 1", Script: "echo after_clone_1 {{release}} {{sha}}"},
				{ID: 4, Name: "After Clone 2", Script: "echo after_clone_2 {{branch}}"},
			},
			AfterInstall: []HookSpec{
				{ID: 5, Name: "Migrate", Script: "echo after_install_1 {{release}}"},
				{ID: 6, Name: "Optimize", Script: "echo after_install_2 {{php}}"},
			},
			AfterActivate: []HookSpec{
				{ID: 7, Name: "Reload Queue", Script: "echo after_activate_1 {{release}}"},
				{ID: 8, Name: "Warm Cache", Script: "echo after_activate_2"},
			},
			AfterPurge: []HookSpec{
				{ID: 9, Name: "Notify Slack", Script: "echo after_purge_1 {{release}}"},
				{ID: 10, Name: "Record Audit", Script: "echo after_purge_2"},
			},
		},
	}
	specPath := writeTestSpec(t, domainRoot, spec)

	var trace []string
	var execRequests []TenantExecRequest

	deps := runnerDeps{
		executeTenant: func(ctx context.Context, req TenantExecRequest) TenantExecResult {
			execRequests = append(execRequests, req)
			firstLine := strings.SplitN(req.Script, "\n", 2)[0]
			trace = append(trace, "exec:"+firstLine)
			return TenantExecResult{ExitCode: 0, Duration: 12 * time.Millisecond}
		},
		cloneRelease: func(ctx context.Context, s Payload, fp *FetchPayload, rDir string, uid, gid int, lw io.Writer) (CommitMeta, error) {
			trace = append(trace, "stage:clone")
			return CommitMeta{SHA: "deadbeef1234", Message: "feat: 9-phase", Author: "Alice"}, nil
		},
		activateRelease: func(ctx context.Context, s Payload, rDir, curLink, prevDir string, uid, gid int, meta CommitMeta, lw io.Writer) (*DeployResult, error) {
			trace = append(trace, "stage:activate")
			return nil, nil
		},
		purgeReleases: func(dRoot string, keep int, activeDir string) error {
			trace = append(trace, "stage:purge")
			return nil
		},
	}

	var logBuf bytes.Buffer
	res, err := runDeployWithDeps(context.Background(), specPath, nil, &logBuf, deps)
	if err != nil {
		t.Fatalf("runDeployWithDeps() unexpected error: %v", err)
	}
	if res.Status != "active" || res.CommitSHA != "deadbeef1234" {
		t.Fatalf("unexpected DeployResult: %+v", res)
	}

	expectedShared := filepath.Join(domainRoot, "shared")
	wantTrace := []string{
		"exec:echo before_clone_1 " + domainRoot,
		"exec:echo before_clone_2 " + expectedShared,
		"stage:clone",
		"exec:echo after_clone_1 " + releaseDir + " deadbeef1234",
		"exec:echo after_clone_2 main",
		"exec:/usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer install --no-dev",
		"exec:echo after_install_1 " + releaseDir,
		"exec:echo after_install_2 /usr/local/lsws/lsphp83/bin/php",
		"stage:activate",
		"exec:echo after_activate_1 " + releaseDir,
		"exec:echo after_activate_2",
		"stage:purge",
		"exec:echo after_purge_1 " + releaseDir,
		"exec:echo after_purge_2",
	}

	if !reflect.DeepEqual(trace, wantTrace) {
		t.Fatalf("9-phase execution order mismatch:\ngot:  %#v\nwant: %#v", trace, wantTrace)
	}

	// Verify Domain user, working directory, timeout, and CRLF normalization for every execution.
	if len(execRequests) != 11 {
		t.Fatalf("expected 11 tenant executions (10 hooks + Stage 2), got %d", len(execRequests))
	}
	for i, req := range execRequests {
		if req.Username != "tenant_alice" {
			t.Fatalf("exec[%d] username = %q, want %q", i, req.Username, "tenant_alice")
		}
		if req.Timeout != 45*time.Second {
			t.Fatalf("exec[%d] timeout = %s, want 45s", i, req.Timeout)
		}
		if strings.Contains(req.Script, "\r") {
			t.Fatalf("exec[%d] script still contains CR: %q", i, req.Script)
		}
		wantCwd := releaseDir
		if i < 2 {
			wantCwd = domainRoot
		}
		if req.WorkDir != wantCwd {
			t.Fatalf("exec[%d] cwd = %q, want %q", i, req.WorkDir, wantCwd)
		}
	}

	// Verify structured log records contain required fields.
	entries := parseStructuredLogs(t, logBuf.String())
	if len(entries) != 14 {
		t.Fatalf("expected 14 structured log entries (10 hooks + 4 stages), got %d", len(entries))
	}
	if entries[0].SiteID != 42 || entries[0].DeploymentID != 108 || entries[0].Slot != SlotBeforeClone || entries[0].HookID != 1 || entries[0].HookName != "Pre-flight 1" || entries[0].Cwd != domainRoot || entries[0].ExitCode != 0 {
		t.Fatalf("unexpected first structured log entry: %+v", entries[0])
	}
}

func TestRunDeploy_SkipsEmptyOrNullInstallScriptAndProceedsToAfterInstall(t *testing.T) {
	cases := []struct {
		name          string
		installScript *string
	}{
		{name: "null", installScript: nil},
		{name: "whitespace_only", installScript: strPtr("   \r\n\t  ")},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			domainRoot := t.TempDir()
			releaseID := "20261126_020000_skip"
			spec := Payload{
				SiteID:        7,
				DeploymentID:  21,
				ReleaseID:     releaseID,
				LinuxUser:     "tenant_bob",
				Domain:        "bob.example.com",
				DomainRoot:    domainRoot,
				InstallScript: tc.installScript,
				Hooks: HooksConfig{
					AfterInstall: []HookSpec{
						{ID: 55, Name: "Post Install Check", Script: "echo after_install_ran"},
					},
				},
			}
			specPath := writeTestSpec(t, domainRoot, spec)

			var executed []string
			deps := runnerDeps{
				executeTenant: func(ctx context.Context, req TenantExecRequest) TenantExecResult {
					executed = append(executed, req.Script)
					return TenantExecResult{ExitCode: 0, Duration: 5 * time.Millisecond}
				},
				cloneRelease: func(ctx context.Context, s Payload, fp *FetchPayload, rDir string, uid, gid int, lw io.Writer) (CommitMeta, error) {
					return CommitMeta{SHA: "11112222"}, nil
				},
				activateRelease: func(ctx context.Context, s Payload, rDir, curLink, prevDir string, uid, gid int, meta CommitMeta, lw io.Writer) (*DeployResult, error) {
					return nil, nil
				},
				purgeReleases: func(dRoot string, keep int, activeDir string) error {
					return nil
				},
			}

			var logBuf bytes.Buffer
			res, err := runDeployWithDeps(context.Background(), specPath, nil, &logBuf, deps)
			if err != nil {
				t.Fatalf("expected clean deployment when install_script is %s, got err: %v", tc.name, err)
			}
			if res.Status != "active" {
				t.Fatalf("expected active status, got %q", res.Status)
			}
			if !reflect.DeepEqual(executed, []string{"echo after_install_ran"}) {
				t.Fatalf("expected only after_install hook to run, got %#v", executed)
			}
			if !strings.Contains(logBuf.String(), "Skipped") {
				t.Fatalf("expected log to mention Skipped, got:\n%s", logBuf.String())
			}

			entries := parseStructuredLogs(t, logBuf.String())
			foundSkipped := false
			for _, e := range entries {
				if e.Stage == StageInstall && e.Status == "skipped" && e.Event == "stage_skipped" && e.ExitCode == 0 {
					foundSkipped = true
					break
				}
			}
			if !foundSkipped {
				t.Fatalf("expected structured stage_skipped log entry for Stage 2, got %+v", entries)
			}
		})
	}
}

func TestRunDeploy_RejectsReleasePlaceholderInBeforeCloneFailFast(t *testing.T) {
	domainRoot := t.TempDir()
	spec := Payload{
		SiteID:       12,
		DeploymentID: 34,
		ReleaseID:    "20261126_030000_bad",
		LinuxUser:    "tenant_carol",
		Domain:       "carol.example.com",
		DomainRoot:   domainRoot,
		Hooks: HooksConfig{
			BeforeClone: []HookSpec{
				{ID: 77, Name: "Invalid Pre-Clone", Script: "cd {{release}} && pwd"},
			},
		},
	}
	specPath := writeTestSpec(t, domainRoot, spec)

	cloneCalled := false
	execCalled := false
	deps := runnerDeps{
		executeTenant: func(ctx context.Context, req TenantExecRequest) TenantExecResult {
			execCalled = true
			return TenantExecResult{ExitCode: 0}
		},
		cloneRelease: func(ctx context.Context, s Payload, fp *FetchPayload, rDir string, uid, gid int, lw io.Writer) (CommitMeta, error) {
			cloneCalled = true
			return CommitMeta{}, nil
		},
		activateRelease: func(ctx context.Context, s Payload, rDir, curLink, prevDir string, uid, gid int, meta CommitMeta, lw io.Writer) (*DeployResult, error) {
			return nil, nil
		},
		purgeReleases: func(dRoot string, keep int, activeDir string) error {
			return nil
		},
	}

	var logBuf bytes.Buffer
	_, err := runDeployWithDeps(context.Background(), specPath, nil, &logBuf, deps)
	if err == nil {
		t.Fatalf("expected error for {{release}} in before_clone, got nil")
	}
	if execCalled || cloneCalled {
		t.Fatalf("expected fail-fast before execution or clone (execCalled=%v, cloneCalled=%v)", execCalled, cloneCalled)
	}

	entries := parseStructuredLogs(t, logBuf.String())
	if len(entries) == 0 || entries[0].Event != "hook_failed" || entries[0].Slot != SlotBeforeClone || entries[0].HookID != 77 || entries[0].ExitCode != 1 {
		t.Fatalf("expected structured hook_failed log entry, got %+v", entries)
	}
}

func TestRunDeploy_AbortsImmediatelyOnNonZeroExitCodeAndRecordsStructuredError(t *testing.T) {
	domainRoot := t.TempDir()
	releaseID := "20261126_040000_fail"
	releaseDir := filepath.Join(domainRoot, "releases", releaseID)

	spec := Payload{
		SiteID:       88,
		DeploymentID: 204,
		ReleaseID:    releaseID,
		LinuxUser:    "tenant_dave",
		Domain:       "dave.example.com",
		DomainRoot:   domainRoot,
		Hooks: HooksConfig{
			AfterClone: []HookSpec{
				{ID: 101, Name: "Failing Hook", Script: "exit 17"},
				{ID: 102, Name: "Must Not Run", Script: "echo should_not_run"},
			},
			AfterInstall: []HookSpec{
				{ID: 103, Name: "Also Must Not Run", Script: "echo nope"},
			},
		},
	}
	specPath := writeTestSpec(t, domainRoot, spec)

	execCount := 0
	activateCalled := false
	deps := runnerDeps{
		executeTenant: func(ctx context.Context, req TenantExecRequest) TenantExecResult {
			execCount++
			return TenantExecResult{
				ExitCode: 17,
				Duration: 35 * time.Millisecond,
				Err:      errors.New("exit status 17"),
			}
		},
		cloneRelease: func(ctx context.Context, s Payload, fp *FetchPayload, rDir string, uid, gid int, lw io.Writer) (CommitMeta, error) {
			return CommitMeta{SHA: "cafebabe"}, nil
		},
		activateRelease: func(ctx context.Context, s Payload, rDir, curLink, prevDir string, uid, gid int, meta CommitMeta, lw io.Writer) (*DeployResult, error) {
			activateCalled = true
			return nil, nil
		},
		purgeReleases: func(dRoot string, keep int, activeDir string) error {
			return nil
		},
	}

	var logBuf bytes.Buffer
	_, err := runDeployWithDeps(context.Background(), specPath, nil, &logBuf, deps)
	if err == nil {
		t.Fatalf("expected deployment to abort on non-zero exit code")
	}
	if execCount != 1 {
		t.Fatalf("expected strict fail-fast after 1st hook, got %d executions", execCount)
	}
	if activateCalled {
		t.Fatalf("expected Stage 3 Activate not to run after hook failure")
	}

	entries := parseStructuredLogs(t, logBuf.String())
	var failEntry *ExecutionLogEntry
	for i := range entries {
		if entries[i].Event == "hook_failed" {
			failEntry = &entries[i]
			break
		}
	}
	if failEntry == nil {
		t.Fatalf("expected structured hook_failed entry, got %+v", entries)
	}
	if failEntry.SiteID != 88 || failEntry.DeploymentID != 204 || failEntry.Slot != SlotAfterClone || failEntry.HookID != 101 || failEntry.HookName != "Failing Hook" || failEntry.Cwd != releaseDir || failEntry.ExitCode != 17 || failEntry.DurationMs != 35 {
		t.Fatalf("unexpected structured failure context: %+v", *failEntry)
	}
}

func TestRunDeploy_AbortsImmediatelyOnTimeoutAndRecordsStructuredError(t *testing.T) {
	domainRoot := t.TempDir()
	releaseID := "20261126_050000_timeout"
	releaseDir := filepath.Join(domainRoot, "releases", releaseID)

	spec := Payload{
		SiteID:             91,
		DeploymentID:       305,
		ReleaseID:          releaseID,
		LinuxUser:          "tenant_eve",
		Domain:             "eve.example.com",
		DomainRoot:         domainRoot,
		InstallScript:      strPtr("sleep 60"),
		HookTimeoutSeconds: 2,
		Hooks: HooksConfig{
			AfterInstall: []HookSpec{
				{ID: 201, Name: "Must Not Run After Timeout", Script: "echo never"},
			},
		},
	}
	specPath := writeTestSpec(t, domainRoot, spec)

	execCount := 0
	activateCalled := false
	deps := runnerDeps{
		executeTenant: func(ctx context.Context, req TenantExecRequest) TenantExecResult {
			execCount++
			if req.Timeout != 2*time.Second {
				t.Errorf("expected 2s timeout, got %s", req.Timeout)
			}
			return TenantExecResult{
				ExitCode: TimeoutExitCode,
				Duration: 2 * time.Second,
				TimedOut: true,
				Err:      context.DeadlineExceeded,
			}
		},
		cloneRelease: func(ctx context.Context, s Payload, fp *FetchPayload, rDir string, uid, gid int, lw io.Writer) (CommitMeta, error) {
			return CommitMeta{SHA: "feedface"}, nil
		},
		activateRelease: func(ctx context.Context, s Payload, rDir, curLink, prevDir string, uid, gid int, meta CommitMeta, lw io.Writer) (*DeployResult, error) {
			activateCalled = true
			return nil, nil
		},
		purgeReleases: func(dRoot string, keep int, activeDir string) error {
			return nil
		},
	}

	var logBuf bytes.Buffer
	_, err := runDeployWithDeps(context.Background(), specPath, nil, &logBuf, deps)
	if err == nil {
		t.Fatalf("expected deployment to abort on timeout")
	}
	if execCount != 1 {
		t.Fatalf("expected strict fail-fast after timeout, got %d executions", execCount)
	}
	if activateCalled {
		t.Fatalf("expected Stage 3 Activate not to run after Stage 2 timeout")
	}

	entries := parseStructuredLogs(t, logBuf.String())
	var timeoutEntry *ExecutionLogEntry
	for i := range entries {
		if entries[i].Event == "stage_timeout" {
			timeoutEntry = &entries[i]
			break
		}
	}
	if timeoutEntry == nil {
		t.Fatalf("expected structured stage_timeout entry, got %+v", entries)
	}
	if timeoutEntry.SiteID != 91 || timeoutEntry.DeploymentID != 305 || timeoutEntry.Stage != StageInstall || timeoutEntry.Cwd != releaseDir || timeoutEntry.ExitCode != TimeoutExitCode || timeoutEntry.Status != "timeout" {
		t.Fatalf("unexpected structured timeout context: %+v", *timeoutEntry)
	}
}
