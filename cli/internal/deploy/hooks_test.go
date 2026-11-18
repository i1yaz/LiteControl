package deploy

import (
	"path/filepath"
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
