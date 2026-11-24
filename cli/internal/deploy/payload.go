package deploy

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"
)

const (
	// SupportedFetchSchemaVersion is the only stdin fetch payload schema version supported by this binary.
	SupportedFetchSchemaVersion = 1

	// DefaultStdinReadTimeout bounds stdin reads so an interactive TTY cannot hang lc-cli.
	DefaultStdinReadTimeout = 10 * time.Second

	// MaxStdinPayloadBytes caps the size of the JSON stdin fetch payload.
	MaxStdinPayloadBytes = 1 << 20 // 1 MiB
)

// FetchCredentials holds short-lived execution-time credentials received over stdin.
// String() and GoString() always return "[REDACTED]" so logs and panics never leak tokens.
type FetchCredentials struct {
	AuthHeader    string `json:"auth_header,omitempty"`
	HTTPSUsername string `json:"https_username,omitempty"`
	HTTPSToken    string `json:"https_token,omitempty"`
}

// String implements fmt.Stringer with strict redaction.
func (c FetchCredentials) String() string {
	return "[REDACTED]"
}

// GoString implements fmt.GoStringer with strict redaction.
func (c FetchCredentials) GoString() string {
	return "[REDACTED]"
}

// FetchPayload represents the versioned JSON payload piped from Laravel over stdin
// for provider-mode deployments.
type FetchPayload struct {
	SchemaVersion int              `json:"schema_version"`
	Mode          string           `json:"mode"`
	CommitSHA     string           `json:"commit_sha"`
	Branch        string           `json:"branch,omitempty"`
	TarballURL    string           `json:"tarball_url,omitempty"`
	CloneURL      string           `json:"clone_url,omitempty"`
	Credentials   FetchCredentials `json:"credentials,omitempty"`
}

// String implements fmt.Stringer with credentials redacted.
func (p FetchPayload) String() string {
	return fmt.Sprintf(
		"FetchPayload{SchemaVersion:%d Mode:%q CommitSHA:%q Branch:%q TarballURL:%q CloneURL:%q Credentials:[REDACTED]}",
		p.SchemaVersion,
		p.Mode,
		p.CommitSHA,
		p.Branch,
		p.TarballURL,
		p.CloneURL,
	)
}

// UnmarshalJSON accepts both flat credential keys (auth_header, https_username, https_token)
// and a nested credentials object.
func (p *FetchPayload) UnmarshalJSON(data []byte) error {
	type rawPayload struct {
		SchemaVersion int               `json:"schema_version"`
		Mode          string            `json:"mode"`
		CommitSHA     string            `json:"commit_sha"`
		Branch        string            `json:"branch"`
		TarballURL    string            `json:"tarball_url"`
		CloneURL      string            `json:"clone_url"`
		AuthHeader    string            `json:"auth_header"`
		HTTPSUsername string            `json:"https_username"`
		HTTPSToken    string            `json:"https_token"`
		Credentials   *FetchCredentials `json:"credentials"`
	}

	var raw rawPayload
	if err := json.Unmarshal(data, &raw); err != nil {
		return err
	}

	creds := FetchCredentials{
		AuthHeader:    raw.AuthHeader,
		HTTPSUsername: raw.HTTPSUsername,
		HTTPSToken:    raw.HTTPSToken,
	}
	if raw.Credentials != nil {
		if creds.AuthHeader == "" {
			creds.AuthHeader = raw.Credentials.AuthHeader
		}
		if creds.HTTPSUsername == "" {
			creds.HTTPSUsername = raw.Credentials.HTTPSUsername
		}
		if creds.HTTPSToken == "" {
			creds.HTTPSToken = raw.Credentials.HTTPSToken
		}
	}

	p.SchemaVersion = raw.SchemaVersion
	p.Mode = raw.Mode
	p.CommitSHA = raw.CommitSHA
	p.Branch = raw.Branch
	p.TarballURL = raw.TarballURL
	p.CloneURL = raw.CloneURL
	p.Credentials = creds

	return nil
}

// Validate enforces schema_version == 1 and required mode-specific fields.
func (p FetchPayload) Validate() error {
	if p.SchemaVersion != SupportedFetchSchemaVersion {
		return fmt.Errorf(
			"unsupported fetch payload schema_version %d: this lc-cli binary requires schema_version %d (upgrade lc-cli or panel to match)",
			p.SchemaVersion,
			SupportedFetchSchemaVersion,
		)
	}

	if strings.TrimSpace(p.CommitSHA) == "" {
		return errors.New("fetch payload requires a non-empty commit_sha")
	}

	switch p.Mode {
	case "tarball":
		if strings.TrimSpace(p.TarballURL) == "" {
			return errors.New("tarball fetch payload requires tarball_url")
		}
		if strings.TrimSpace(p.Credentials.AuthHeader) == "" {
			return errors.New("tarball fetch payload requires auth_header")
		}
	case "shallow_clone":
		if strings.TrimSpace(p.CloneURL) == "" {
			return errors.New("shallow_clone fetch payload requires clone_url")
		}
		if strings.TrimSpace(p.Branch) == "" {
			return errors.New("shallow_clone fetch payload requires branch")
		}
		if strings.TrimSpace(p.Credentials.HTTPSUsername) == "" || strings.TrimSpace(p.Credentials.HTTPSToken) == "" {
			return errors.New("shallow_clone fetch payload requires https_username and https_token")
		}
	default:
		return fmt.Errorf("unsupported fetch payload mode %q: expected \"tarball\" or \"shallow_clone\"", p.Mode)
	}

	return nil
}

// ReadFetchPayload reads and validates a versioned FetchPayload from r with a strict timeout.
func ReadFetchPayload(r io.Reader, timeout time.Duration) (FetchPayload, error) {
	if timeout <= 0 {
		timeout = DefaultStdinReadTimeout
	}

	type readResult struct {
		data []byte
		err  error
	}

	ch := make(chan readResult, 1)
	go func() {
		limited := io.LimitReader(r, MaxStdinPayloadBytes+1)
		data, err := io.ReadAll(limited)
		ch <- readResult{data: data, err: err}
	}()

	var raw []byte
	select {
	case res := <-ch:
		if res.err != nil {
			return FetchPayload{}, fmt.Errorf("read stdin fetch payload: %w", res.err)
		}
		if int64(len(res.data)) > MaxStdinPayloadBytes {
			return FetchPayload{}, fmt.Errorf("stdin fetch payload exceeds maximum allowed size of %d bytes", MaxStdinPayloadBytes)
		}
		raw = res.data
	case <-time.After(timeout):
		return FetchPayload{}, fmt.Errorf("timed out after %s waiting for fetch payload on stdin", timeout)
	}

	if len(strings.TrimSpace(string(raw))) == 0 {
		return FetchPayload{}, errors.New("empty fetch payload received on stdin")
	}

	var payload FetchPayload
	if err := json.Unmarshal(raw, &payload); err != nil {
		return FetchPayload{}, fmt.Errorf("parse stdin fetch payload JSON: %w", err)
	}

	if err := payload.Validate(); err != nil {
		return FetchPayload{}, err
	}

	return payload, nil
}

// Payload represents the JSON execution spec written by Laravel (deploy_{release_id}.json).
type Payload struct {
	DeploymentID int64             `json:"deployment_id"`
	ReleaseID    string            `json:"release_id"`
	LinuxUser    string            `json:"linux_user"`
	Domain       string            `json:"domain"`
	DomainRoot   string            `json:"domain_root"`
	WebSubpath   string            `json:"web_subpath"`
	PhpBinary    string            `json:"php_binary"`
	ComposerCmd  string            `json:"composer_cmd"`
	Repo         RepoConfig        `json:"repo"`
	SharedDirs   []string          `json:"shared_dirs"`
	SharedFiles  []string          `json:"shared_files"`
	KeepReleases int               `json:"keep_releases"`
	HealthCheck  HealthCheckConfig `json:"health_check"`
	Hooks        HooksConfig       `json:"hooks"`
}

// RepoConfig describes the repository source and fetch driver.
type RepoConfig struct {
	Driver     string `json:"driver"`
	URL        string `json:"url"`
	Branch     string `json:"branch"`
	SSHKeyPath string `json:"ssh_key_path"`
	TarballURL string `json:"tarball_url"`
	AuthToken  string `json:"auth_token"`
}

// HealthCheckConfig describes post-activation HTTP verification.
type HealthCheckConfig struct {
	Enabled        bool   `json:"enabled"`
	Path           string `json:"path"`
	TimeoutSeconds int    `json:"timeout_seconds"`
	Retries        int    `json:"retries"`
	AutoRollback   bool   `json:"auto_rollback"`
}

// HooksConfig holds ordered shell commands for each lifecycle stage.
type HooksConfig struct {
	BeforeClone    []string `json:"before_clone"`
	AfterClone     []string `json:"after_clone"`
	BeforeInstall  []string `json:"before_install"`
	Install        []string `json:"install"`
	AfterInstall   []string `json:"after_install"`
	BeforeActivate []string `json:"before_activate"`
	AfterActivate  []string `json:"after_activate"`
	BeforePurge    []string `json:"before_purge"`
	AfterPurge     []string `json:"after_purge"`
}

// CommitMeta holds git commit metadata extracted before .git is removed.
type CommitMeta struct {
	SHA     string
	Message string
	Author  string
}
