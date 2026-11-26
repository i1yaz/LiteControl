package deploy

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const (
	// AskpassDirPrefix identifies temporary GIT_ASKPASS directories created by lc-cli.
	AskpassDirPrefix = "lc-askpass-"

	// DefaultStaleAskpassAge is the age threshold after which crashed askpass dirs are swept.
	DefaultStaleAskpassAge = time.Hour
)

// SweepStaleAskpassDirs removes any leftover lc-askpass-* temporary directories older than
// maxAge (e.g., from a previous SIGKILL or OOM crash).
func SweepStaleAskpassDirs(baseTempDir string, maxAge time.Duration) error {
	if strings.TrimSpace(baseTempDir) == "" {
		baseTempDir = os.TempDir()
	}
	if maxAge <= 0 {
		maxAge = DefaultStaleAskpassAge
	}

	entries, err := os.ReadDir(baseTempDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("read temp dir %s: %w", baseTempDir, err)
	}

	cutoff := time.Now().Add(-maxAge)
	for _, entry := range entries {
		if !entry.IsDir() || !strings.HasPrefix(entry.Name(), AskpassDirPrefix) {
			continue
		}

		info, err := entry.Info()
		if err != nil {
			continue
		}

		if info.ModTime().Before(cutoff) {
			_ = os.RemoveAll(filepath.Join(baseTempDir, entry.Name()))
		}
	}

	return nil
}

// CreateAskpassHelper creates a 0700 temporary directory and GIT_ASKPASS script that
// responds to Git's Username and Password prompts without ever placing tokens on the CLI.
func CreateAskpassHelper(baseTempDir string, creds FetchCredentials, uid, gid int) (string, string, func(), error) {
	if strings.TrimSpace(baseTempDir) == "" {
		baseTempDir = os.TempDir()
	}

	tempDir, err := os.MkdirTemp(baseTempDir, AskpassDirPrefix)
	if err != nil {
		return "", "", nil, fmt.Errorf("create askpass temp dir: %w", err)
	}

	cleanup := func() {
		_ = os.RemoveAll(tempDir)
	}

	if err := os.Chmod(tempDir, 0o700); err != nil {
		cleanup()
		return "", "", nil, fmt.Errorf("chmod askpass temp dir: %w", err)
	}

	scriptPath := filepath.Join(tempDir, "askpass.sh")
	scriptBody := buildAskpassScript(creds)

	if err := os.WriteFile(scriptPath, []byte(scriptBody), 0o700); err != nil {
		cleanup()
		return "", "", nil, fmt.Errorf("write askpass script: %w", err)
	}

	if runtime.GOOS != "windows" && os.Geteuid() == 0 && uid > 0 {
		if err := os.Chown(tempDir, uid, gid); err != nil {
			cleanup()
			return "", "", nil, fmt.Errorf("chown askpass temp dir: %w", err)
		}
		if err := os.Chown(scriptPath, uid, gid); err != nil {
			cleanup()
			return "", "", nil, fmt.Errorf("chown askpass script: %w", err)
		}
	}

	return tempDir, scriptPath, cleanup, nil
}

func buildAskpassScript(creds FetchCredentials) string {
	return fmt.Sprintf(
		"#!/bin/sh\n"+
			"case \"$1\" in\n"+
			"  *[Uu]sername*) printf '%%s\\n' %s ;;\n"+
			"  *[Pp]assword*) printf '%%s\\n' %s ;;\n"+
			"  *) printf '\\n' ;;\n"+
			"esac\n",
		shellQuote(creds.HTTPSUsername),
		shellQuote(creds.HTTPSToken),
	)
}

// FetchProviderShallowClone clones a repository over HTTPS using a temporary 0700 GIT_ASKPASS
// helper, strips .git, and deletes partial release directories on error.
func FetchProviderShallowClone(
	ctx context.Context,
	spec Payload,
	fetch FetchPayload,
	releaseDir string,
	logWriter io.Writer,
) (CommitMeta, error) {
	_ = SweepStaleAskpassDirs("", DefaultStaleAskpassAge)

	success := false
	defer func() {
		if !success {
			_ = os.RemoveAll(releaseDir)
		}
	}()

	uid, gid, err := lookupUIDGID(spec.LinuxUser)
	if err != nil {
		return CommitMeta{}, err
	}

	_, scriptPath, cleanup, err := CreateAskpassHelper("", fetch.Credentials, uid, gid)
	if err != nil {
		return CommitMeta{}, err
	}
	defer cleanup()

	env := []string{
		"GIT_TERMINAL_PROMPT=0",
		"GIT_ASKPASS=" + scriptPath,
	}

	branch := strings.TrimSpace(fetch.Branch)
	if branch == "" {
		branch = strings.TrimSpace(spec.Repo.Branch)
	}

	cloneCmd := fmt.Sprintf(
		"git clone --depth 1 --single-branch --branch %s %s %s",
		shellQuote(branch),
		shellQuote(fetch.CloneURL),
		shellQuote(releaseDir),
	)

	if err := RunAsTenantUser(ctx, spec.LinuxUser, spec.DomainRoot, env, cloneCmd, logWriter); err != nil {
		return CommitMeta{}, fmt.Errorf("provider shallow clone failed: %w", err)
	}

	var metaBuf bytes.Buffer
	metaCmd := fmt.Sprintf(`git -C %s log -1 --format="%%H|%%s|%%an"`, shellQuote(releaseDir))
	if err := RunAsTenantUser(ctx, spec.LinuxUser, releaseDir, env, metaCmd, &metaBuf); err != nil {
		return CommitMeta{}, fmt.Errorf("extract commit metadata: %w", err)
	}
	meta := parseCommitMeta(metaBuf.String())
	if meta.SHA == "" {
		meta.SHA = fetch.CommitSHA
	}

	if err := removeAndVerifyNoGit(releaseDir); err != nil {
		return CommitMeta{}, err
	}

	success = true
	return meta, nil
}
