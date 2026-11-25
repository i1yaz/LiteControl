package deploy

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestInterpolateHook_ReplacesAllTokens(t *testing.T) {
	spec := Payload{
		DomainRoot:  "/home/alice/example.com",
		PhpBinary:   "/usr/local/lsws/lsphp83/bin/php",
		ComposerCmd: "/usr/local/bin/composer",
		Repo: RepoConfig{
			Branch: "main",
		},
	}
	releaseDir := "/home/alice/example.com/releases/20261119015500"
	meta := CommitMeta{
		SHA: "abc1234def5678",
	}

	script := "cd {{release}} && {{php}} {{release}}/artisan migrate --force && {{composer}} install --no-dev && echo {{project}} {{shared}} {{branch}} {{sha}}"
	got := InterpolateHook(script, spec, releaseDir, meta)

	expectedShared := filepath.Join("/home/alice/example.com", "shared")
	want := "cd " + releaseDir + " && /usr/local/lsws/lsphp83/bin/php " + releaseDir + "/artisan migrate --force && /usr/local/bin/composer install --no-dev && echo /home/alice/example.com " + expectedShared + " main abc1234def5678"

	if got != want {
		t.Fatalf("InterpolateHook() mismatch:\ngot:  %q\nwant: %q", got, want)
	}
}

func TestInterpolateHook_LeavesUnknownTokensAndAvoidsDoubleReplace(t *testing.T) {
	spec := Payload{
		DomainRoot: "/home/alice/example.com",
		Repo: RepoConfig{
			Branch: "{{sha}}",
		},
	}
	meta := CommitMeta{
		SHA: "deadbeef",
	}

	script := "echo {{branch}} {{unknown}}"
	got := InterpolateHook(script, spec, "/home/alice/example.com/releases/1", meta)
	want := "echo {{sha}} {{unknown}}"

	if got != want {
		t.Fatalf("InterpolateHook() = %q, want %q", got, want)
	}
}

func TestResolveHookScript_RejectsReleaseInBeforeClone(t *testing.T) {
	spec := Payload{
		DomainRoot: "/home/alice/example.com",
		Repo:       RepoConfig{Branch: "main"},
	}

	cases := []string{
		"cd {{release}} && ls",
		"echo {{ release }}",
		"echo {{RELEASE}}",
	}

	for _, script := range cases {
		_, err := ResolveHookScript(SlotBeforeClone, script, spec, "/home/alice/example.com/releases/r1", CommitMeta{})
		if err == nil {
			t.Fatalf("expected error for before_clone script %q, got nil", script)
		}
		if !strings.Contains(err.Error(), "{{release}}") {
			t.Fatalf("expected error mentioning {{release}}, got %v", err)
		}
	}
}

func TestResolveHookScript_AllowsNonReleaseTokensInBeforeCloneAndNormalizesCRLF(t *testing.T) {
	spec := Payload{
		DomainRoot:  "/home/alice/example.com",
		PhpBinary:   "/usr/local/lsws/lsphp83/bin/php",
		ComposerCmd: "/usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer",
		Repo:        RepoConfig{Branch: "release/v2"},
	}
	meta := CommitMeta{SHA: "a1b2c3d4"}

	got, err := ResolveHookScript(SlotBeforeClone, "echo {{project}}\r\necho {{shared}} {{branch}} {{sha}} {{unknown}}\r", spec, "/home/alice/example.com/releases/r1", meta)
	if err != nil {
		t.Fatalf("ResolveHookScript() unexpected error: %v", err)
	}

	expectedShared := filepath.Join("/home/alice/example.com", "shared")
	want := "echo /home/alice/example.com\necho " + expectedShared + " release/v2 a1b2c3d4 {{unknown}}\n"
	if got != want {
		t.Fatalf("ResolveHookScript() mismatch:\ngot:  %q\nwant: %q", got, want)
	}
}

func TestResolveHookScript_RejectsUnsafeDynamicPlaceholderValues(t *testing.T) {
	spec := Payload{
		DomainRoot: "/home/alice/example.com",
		Repo:       RepoConfig{Branch: "main; rm -rf /"},
	}

	if _, err := ResolveHookScript(SlotAfterClone, "echo {{branch}}", spec, "/home/alice/example.com/releases/r1", CommitMeta{}); err == nil {
		t.Fatalf("expected error when {{branch}} contains shell metacharacters, got nil")
	}

	spec.Repo.Branch = "main"
	if _, err := ResolveHookScript(SlotAfterClone, "echo {{sha}}", spec, "/home/alice/example.com/releases/r1", CommitMeta{SHA: "$(whoami)"}); err == nil {
		t.Fatalf("expected error when {{sha}} contains shell metacharacters, got nil")
	}
}

func TestSecurityHelpers_WrapScriptEnvAndDomainUserValidation(t *testing.T) {
	wrapped := WrapScript("echo one\r\necho two\r")
	want := "set -euo pipefail\necho one\necho two\n"
	if wrapped != want {
		t.Fatalf("WrapScript() = %q, want %q", wrapped, want)
	}

	env := BuildTenantEnv("alice", "/home/alice", []string{"FOO=bar"})
	wantEnv := []string{
		"HOME=/home/alice",
		"USER=alice",
		"PATH=" + DefaultTenantPath,
		"FOO=bar",
	}
	if len(env) != len(wantEnv) {
		t.Fatalf("BuildTenantEnv() len = %d, want %d", len(env), len(wantEnv))
	}
	for i := range wantEnv {
		if env[i] != wantEnv[i] {
			t.Fatalf("BuildTenantEnv()[%d] = %q, want %q", i, env[i], wantEnv[i])
		}
	}

	if err := ValidateDomainUser(""); err == nil {
		t.Fatalf("expected error for empty domain user")
	}
	if err := ValidateDomainUser("root"); err == nil {
		t.Fatalf("expected error for root user")
	}
	if err := ValidateDomainUser("alice"); err != nil {
		t.Fatalf("expected valid domain user alice, got %v", err)
	}
}
