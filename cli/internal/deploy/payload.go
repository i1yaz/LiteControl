package deploy

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
