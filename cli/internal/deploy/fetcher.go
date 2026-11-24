package deploy

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// FetchReleaseCode fetches the release source into releaseDir using the configured
// driver and guarantees no .git directory remains on disk.
func FetchReleaseCode(ctx context.Context, spec Payload, releaseDir string, logWriter io.Writer) (CommitMeta, error) {
	return FetchReleaseCodeWithPayload(ctx, spec, nil, releaseDir, logWriter)
}

// FetchReleaseCodeWithPayload fetches release code using either a versioned provider
// FetchPayload (received over stdin) or the legacy manual-mode RepoConfig.
func FetchReleaseCodeWithPayload(
	ctx context.Context,
	spec Payload,
	fetchPayload *FetchPayload,
	releaseDir string,
	logWriter io.Writer,
) (CommitMeta, error) {
	if fetchPayload != nil {
		if err := fetchPayload.Validate(); err != nil {
			return CommitMeta{}, err
		}

		switch fetchPayload.Mode {
		case "tarball":
			_, err := DownloadAndExtractTarball(ctx, TarballFetchOptions{
				URL:             fetchPayload.TarballURL,
				Credentials:     fetchPayload.Credentials,
				ReleaseDir:      releaseDir,
				LinuxUser:       spec.LinuxUser,
				StripComponents: 1,
			}, logWriter)
			if err != nil {
				return CommitMeta{}, err
			}

			return CommitMeta{
				SHA: fetchPayload.CommitSHA,
			}, nil

		case "shallow_clone":
			return FetchProviderShallowClone(ctx, spec, *fetchPayload, releaseDir, logWriter)

		default:
			return CommitMeta{}, fmt.Errorf("unsupported provider fetch mode: %q", fetchPayload.Mode)
		}
	}

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

	meta := fetchTarballCommitMeta(ctx, spec.Repo.TarballURL, spec.Repo.AuthToken)
	return meta, nil
}

// fetchTarballCommitMeta attempts a best-effort provider API lookup to retrieve
// commit SHA, message, and author for GitHub, GitLab, and Bitbucket tarballs.
func fetchTarballCommitMeta(ctx context.Context, tarballURL, authToken string) CommitMeta {
	provider, apiURL := resolveCommitMetaAPIURL(tarballURL)
	if provider == "" || apiURL == "" {
		return CommitMeta{}
	}

	reqCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(reqCtx, http.MethodGet, apiURL, nil)
	if err != nil {
		return CommitMeta{}
	}
	req.Header.Set("User-Agent", "LiteControl-Deploy")
	req.Header.Set("Accept", "application/json")
	if authToken != "" {
		req.Header.Set("Authorization", "Bearer "+authToken)
	}

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return CommitMeta{}
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return CommitMeta{}
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return CommitMeta{}
	}

	return parseProviderCommitMeta(provider, body)
}

func resolveCommitMetaAPIURL(tarballURL string) (provider, apiURL string) {
	u, err := url.Parse(strings.TrimSpace(tarballURL))
	if err != nil || u.Scheme == "" || u.Host == "" {
		return "", ""
	}

	host := strings.ToLower(u.Host)
	path := u.EscapedPath()

	// GitHub: https://api.github.com/repos/{owner}/{repo}/tarball/{ref}
	if strings.Contains(host, "github.com") {
		if idx := strings.Index(path, "/tarball/"); idx != -1 {
			prefix := path[:idx]
			ref := strings.TrimPrefix(path[idx+len("/tarball/"):], "/")
			if prefix != "" && ref != "" {
				return "github", fmt.Sprintf("%s://%s%s/commits/%s", u.Scheme, u.Host, prefix, ref)
			}
		}
	}

	// GitLab: https://{host}/api/v4/projects/{encoded_path}/repository/archive.tar.gz?sha={ref}
	if strings.Contains(path, "/api/v4/projects/") && strings.HasSuffix(path, "/repository/archive.tar.gz") {
		ref := u.Query().Get("sha")
		if ref == "" {
			ref = "HEAD"
		}
		prefix := strings.TrimSuffix(path, "/archive.tar.gz")
		return "gitlab", fmt.Sprintf("%s://%s%s/commits/%s", u.Scheme, u.Host, prefix, url.PathEscape(ref))
	}

	// Bitbucket: https://bitbucket.org/{owner}/{repo}/get/{ref}.tar.gz
	if strings.Contains(host, "bitbucket.org") {
		if idx := strings.Index(path, "/get/"); idx != -1 && strings.HasSuffix(path, ".tar.gz") {
			repoPath := strings.Trim(path[:idx], "/")
			ref := strings.TrimSuffix(path[idx+len("/get/"):], ".tar.gz")
			if repoPath != "" && ref != "" {
				return "bitbucket", fmt.Sprintf("https://api.bitbucket.org/2.0/repositories/%s/commits/%s?pagelen=1", repoPath, ref)
			}
		}
	}

	return "", ""
}

func parseProviderCommitMeta(provider string, body []byte) CommitMeta {
	switch provider {
	case "github":
		var payload struct {
			SHA    string `json:"sha"`
			Commit struct {
				Message string `json:"message"`
				Author  struct {
					Name string `json:"name"`
				} `json:"author"`
			} `json:"commit"`
			Author struct {
				Login string `json:"login"`
			} `json:"author"`
		}
		if err := json.Unmarshal(body, &payload); err != nil {
			return CommitMeta{}
		}
		author := strings.TrimSpace(payload.Commit.Author.Name)
		if author == "" {
			author = strings.TrimSpace(payload.Author.Login)
		}
		return CommitMeta{
			SHA:     strings.TrimSpace(payload.SHA),
			Message: firstLine(payload.Commit.Message),
			Author:  author,
		}

	case "gitlab":
		var payload struct {
			ID         string `json:"id"`
			Title      string `json:"title"`
			Message    string `json:"message"`
			AuthorName string `json:"author_name"`
		}
		if err := json.Unmarshal(body, &payload); err != nil {
			return CommitMeta{}
		}
		msg := strings.TrimSpace(payload.Title)
		if msg == "" {
			msg = firstLine(payload.Message)
		}
		return CommitMeta{
			SHA:     strings.TrimSpace(payload.ID),
			Message: msg,
			Author:  strings.TrimSpace(payload.AuthorName),
		}

	case "bitbucket":
		var payload struct {
			Values []struct {
				Hash    string `json:"hash"`
				Message string `json:"message"`
				Author  struct {
					Raw  string `json:"raw"`
					User struct {
						DisplayName string `json:"display_name"`
					} `json:"user"`
				} `json:"author"`
			} `json:"values"`
		}
		if err := json.Unmarshal(body, &payload); err != nil || len(payload.Values) == 0 {
			return CommitMeta{}
		}
		c := payload.Values[0]
		author := strings.TrimSpace(c.Author.User.DisplayName)
		if author == "" {
			author = strings.TrimSpace(c.Author.Raw)
		}
		return CommitMeta{
			SHA:     strings.TrimSpace(c.Hash),
			Message: firstLine(c.Message),
			Author:  author,
		}
	}

	return CommitMeta{}
}

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if idx := strings.IndexAny(s, "\r\n"); idx != -1 {
		return strings.TrimSpace(s[:idx])
	}
	return s
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
