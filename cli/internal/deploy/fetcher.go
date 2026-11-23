package deploy

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

// FetchReleaseCode fetches the release source into releaseDir using the configured
// driver and guarantees no .git directory remains on disk.
func FetchReleaseCode(ctx context.Context, spec Payload, releaseDir string, logWriter io.Writer) (CommitMeta, error) {
	switch spec.Repo.Driver {
	case "", "shallow_git":
		return fetchShallowGit(ctx, spec, releaseDir, logWriter)
	case "tarball", "provider_tarball":
		return fetchTarball(ctx, spec, releaseDir, logWriter)
	default:
		return CommitMeta{}, fmt.Errorf("unsupported repo driver: %q", spec.Repo.Driver)
	}
}

func fetchShallowGit(ctx context.Context, spec Payload, releaseDir string, logWriter io.Writer) (CommitMeta, error) {
	if spec.Repo.URL == "" {
		return CommitMeta{}, fmt.Errorf("repo.url is required for shallow_git")
	}
	if spec.Repo.Branch == "" {
		return CommitMeta{}, fmt.Errorf("repo.branch is required for shallow_git")
	}

	var env []string
	if spec.Repo.SSHKeyPath != "" {
		env = append(env, fmt.Sprintf("GIT_SSH_COMMAND=ssh -i %s -o StrictHostKeyChecking=accept-new", shellQuote(spec.Repo.SSHKeyPath)))
	}

	cloneCmd := fmt.Sprintf(
		"git clone --depth=1 --single-branch --branch %s --no-tags %s %s",
		shellQuote(spec.Repo.Branch),
		shellQuote(spec.Repo.URL),
		shellQuote(releaseDir),
	)
	if err := RunAsTenantUser(ctx, spec.LinuxUser, spec.DomainRoot, env, cloneCmd, logWriter); err != nil {
		return CommitMeta{}, fmt.Errorf("git clone failed: %w", err)
	}

	var metaBuf bytes.Buffer
	metaCmd := fmt.Sprintf(`git -C %s log -1 --format="%%H|%%s|%%an"`, shellQuote(releaseDir))
	if err := RunAsTenantUser(ctx, spec.LinuxUser, releaseDir, env, metaCmd, &metaBuf); err != nil {
		return CommitMeta{}, fmt.Errorf("extract commit metadata: %w", err)
	}
	meta := parseCommitMeta(metaBuf.String())

	if err := removeAndVerifyNoGit(releaseDir); err != nil {
		return CommitMeta{}, err
	}

	return meta, nil
}

func fetchTarball(ctx context.Context, spec Payload, releaseDir string, logWriter io.Writer) (CommitMeta, error) {
	if spec.Repo.TarballURL == "" {
		return CommitMeta{}, fmt.Errorf("repo.tarball_url is required for tarball driver")
	}

	var authHeader string
	if spec.Repo.AuthToken != "" {
		authHeader = fmt.Sprintf("-H %s ", shellQuote("Authorization: Bearer "+spec.Repo.AuthToken))
	}

	script := fmt.Sprintf(
		"mkdir -p %s && curl -fsSL -H 'User-Agent: LiteControl-Deploy' %s%s | tar -xzf - --strip-components=1 -C %s",
		shellQuote(releaseDir),
		authHeader,
		shellQuote(spec.Repo.TarballURL),
		shellQuote(releaseDir),
	)
	if err := RunAsTenantUser(ctx, spec.LinuxUser, spec.DomainRoot, nil, script, logWriter); err != nil {
		return CommitMeta{}, fmt.Errorf("tarball fetch failed: %w", err)
	}

	if err := removeAndVerifyNoGit(releaseDir); err != nil {
		return CommitMeta{}, err
	}

	return CommitMeta{}, nil
}

func removeAndVerifyNoGit(releaseDir string) error {
	gitDir := filepath.Join(releaseDir, ".git")
	if err := os.RemoveAll(gitDir); err != nil {
		return fmt.Errorf("remove .git directory %s: %w", gitDir, err)
	}
	if _, err := os.Stat(gitDir); !os.IsNotExist(err) {
		return fmt.Errorf(".git directory still exists at %s", gitDir)
	}
	return nil
}

func parseCommitMeta(raw string) CommitMeta {
	line := strings.TrimSpace(raw)
	if line == "" {
		return CommitMeta{}
	}
	parts := strings.SplitN(line, "|", 3)
	meta := CommitMeta{SHA: strings.TrimSpace(parts[0])}
	if len(parts) > 1 {
		meta.Message = strings.TrimSpace(parts[1])
	}
	if len(parts) > 2 {
		meta.Author = strings.TrimSpace(parts[2])
	}
	return meta
}

func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", "'\"'\"'") + "'"
}
