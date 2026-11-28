package cmd

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"

	"github.com/litecontrol/lc-cli/internal/mail"
)

// ExecuteMail handles `lc-mail <subcommand> [flags]` (and `lc-cli mail <subcommand> [flags]`).
func ExecuteMail(args []string) error {
	if len(args) == 0 {
		printMailUsage()
		return fmt.Errorf("missing mail subcommand")
	}

	switch args[0] {
	case "install-core":
		return runMailInstallCore(args[1:])
	case "uninstall-core":
		return runMailUninstallCore(args[1:])
	case "reconcile":
		return runMailReconcile(args[1:])
	case "evict-session":
		return runMailEvictSession(args[1:])
	case "purge-mailbox":
		return runMailPurgeMailbox(args[1:])
	case "quota-usage":
		return runMailQuotaUsage(args[1:])
	case "dkim-generate":
		return runMailDkimGenerate(args[1:])
	case "dkim-remove":
		return runMailDkimRemove(args[1:])
	case "filter-enable":
		return runMailFilterEnable(args[1:])
	case "filter-demote":
		return runMailFilterDemote(args[1:])
	case "filter-status":
		return runMailFilterStatus(args[1:])
	case "webmail-install":
		return runMailWebmailInstall(args[1:])
	case "webmail-uninstall":
		return runMailWebmailUninstall(args[1:])
	case "webmail-status":
		return runMailWebmailStatus(args[1:])
	case "-h", "--help", "help":
		printMailUsage()
		return nil
	default:
		printMailUsage()
		return fmt.Errorf("unknown mail subcommand: %s", args[0])
	}
}

func envOrDefault(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func runMailInstallCore(args []string) error {
	fs := flag.NewFlagSet("mail install-core", flag.ContinueOnError)
	hostname := fs.String("hostname", "", "Mail server FQDN hostname (required)")
	dbHost := fs.String("db-host", envOrDefault("MAIL_DB_HOST", "127.0.0.1"), "MariaDB host")
	dbName := fs.String("db-name", envOrDefault("MAIL_DB_NAME", "litecontrol"), "MariaDB database name")
	dbUser := fs.String("db-user", "lc_mail_ro", "MariaDB read-only mail user")
	dbPassword := fs.String("db-password", os.Getenv("MAIL_RO_DB_PASSWORD"), "MariaDB read-only mail password")
	fallbackCert := fs.String("fallback-cert", "", "Fallback TLS certificate path")
	fallbackKey := fs.String("fallback-key", "", "Fallback TLS private key path")
	skipPackages := fs.Bool("skip-packages", false, "Skip OS package repository setup and installation")
	skipReconcile := fs.Bool("skip-reconcile", false, "Skip post-install reconcile trigger")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *hostname == "" {
		fs.Usage()
		return fmt.Errorf("--hostname is required")
	}

	res, err := mail.InstallCore(context.Background(), mail.InstallOptions{
		Hostname:      *hostname,
		DBHost:        *dbHost,
		DBName:        *dbName,
		DBUser:        *dbUser,
		DBPassword:    *dbPassword,
		FallbackCert:  *fallbackCert,
		FallbackKey:   *fallbackKey,
		SkipPackages:  *skipPackages,
		SkipReconcile: *skipReconcile,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			_, err := mail.Reconcile(ctx, mail.ReconcileOptions{
				Scope:      scope,
				DBHost:     *dbHost,
				DBName:     *dbName,
				DBUser:     *dbUser,
				DBPassword: *dbPassword,
			})
			return err
		},
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailUninstallCore(args []string) error {
	fs := flag.NewFlagSet("mail uninstall-core", flag.ContinueOnError)
	purgeData := fs.Bool("purge-data", false, "Permanently remove /var/vmail mail data")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.UninstallCore(context.Background(), mail.UninstallOptions{
		PurgeData: *purgeData,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailReconcile(args []string) error {
	fs := flag.NewFlagSet("mail reconcile", flag.ContinueOnError)
	scope := fs.String("scope", "all", "Reconcile scope: all, sni, dkim, filter, srs")
	dbHost := fs.String("db-host", envOrDefault("MAIL_DB_HOST", "127.0.0.1"), "MariaDB host")
	dbName := fs.String("db-name", envOrDefault("MAIL_DB_NAME", "litecontrol"), "MariaDB database name")
	dbUser := fs.String("db-user", "lc_mail_ro", "MariaDB read-only mail user")
	dbPassword := fs.String("db-password", os.Getenv("MAIL_RO_DB_PASSWORD"), "MariaDB read-only mail password")
	certStore := fs.String("cert-store", "", "TLS certificate store directory (defaults to /etc/letsencrypt/live)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.Reconcile(context.Background(), mail.ReconcileOptions{
		Scope:        *scope,
		DBHost:       *dbHost,
		DBName:       *dbName,
		DBUser:       *dbUser,
		DBPassword:   *dbPassword,
		CertStoreDir: *certStore,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailEvictSession(args []string) error {
	fs := flag.NewFlagSet("mail evict-session", flag.ContinueOnError)
	email := fs.String("email", "", "Email address to evict (required)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *email == "" {
		fs.Usage()
		return fmt.Errorf("--email is required")
	}

	res, err := mail.EvictSession(context.Background(), mail.EvictSessionOptions{
		Email: *email,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailPurgeMailbox(args []string) error {
	fs := flag.NewFlagSet("mail purge-mailbox", flag.ContinueOnError)
	domain := fs.String("domain", "", "Mail domain (required)")
	user := fs.String("user", "", "Mailbox local part (required)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *domain == "" || *user == "" {
		fs.Usage()
		return fmt.Errorf("--domain and --user are required")
	}

	res, err := mail.PurgeMailbox(context.Background(), mail.PurgeMailboxOptions{
		Domain: *domain,
		User:   *user,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailQuotaUsage(args []string) error {
	fs := flag.NewFlagSet("mail quota-usage", flag.ContinueOnError)
	domain := fs.String("domain", "", "Mail domain (required)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *domain == "" {
		fs.Usage()
		return fmt.Errorf("--domain is required")
	}

	usage, err := mail.QuotaUsage(context.Background(), mail.QuotaUsageOptions{
		Domain: *domain,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(usage)
}

func runMailDkimGenerate(args []string) error {
	fs := flag.NewFlagSet("mail dkim-generate", flag.ContinueOnError)
	domain := fs.String("domain", "", "Mail domain (required)")
	selector := fs.String("selector", "", "DKIM selector (required)")
	algo := fs.String("algo", "rsa2048", "DKIM algorithm: rsa2048 or ed25519")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *domain == "" || *selector == "" {
		fs.Usage()
		return fmt.Errorf("--domain and --selector are required")
	}

	res, err := mail.DkimGenerate(context.Background(), mail.DkimGenerateOptions{
		Domain:    *domain,
		Selector:  *selector,
		Algorithm: *algo,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailDkimRemove(args []string) error {
	fs := flag.NewFlagSet("mail dkim-remove", flag.ContinueOnError)
	domain := fs.String("domain", "", "Mail domain (required)")
	selector := fs.String("selector", "", "DKIM selector (required)")
	dbHost := fs.String("db-host", envOrDefault("MAIL_DB_HOST", "127.0.0.1"), "MariaDB host")
	dbName := fs.String("db-name", envOrDefault("MAIL_DB_NAME", "litecontrol"), "MariaDB database name")
	dbUser := fs.String("db-user", "lc_mail_ro", "MariaDB read-only mail user")
	dbPassword := fs.String("db-password", os.Getenv("MAIL_RO_DB_PASSWORD"), "MariaDB read-only mail password")
	skipReconcile := fs.Bool("skip-reconcile", false, "Skip post-removal DKIM reconcile")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *domain == "" || *selector == "" {
		fs.Usage()
		return fmt.Errorf("--domain and --selector are required")
	}

	res, err := mail.DkimRemove(context.Background(), mail.DkimRemoveOptions{
		Domain:        *domain,
		Selector:      *selector,
		SkipReconcile: *skipReconcile,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			_, err := mail.Reconcile(ctx, mail.ReconcileOptions{
				Scope:      scope,
				DBHost:     *dbHost,
				DBName:     *dbName,
				DBUser:     *dbUser,
				DBPassword: *dbPassword,
			})
			return err
		},
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailFilterEnable(args []string) error {
	fs := flag.NewFlagSet("mail filter-enable", flag.ContinueOnError)
	driver := fs.String("driver", "rspamd", "Anti-spam filter driver (rspamd)")
	withClamAV := fs.Bool("with-clamav", false, "Also enable ClamAV antivirus scanning (requires >= 4096 MB RAM)")
	dbHost := fs.String("db-host", envOrDefault("MAIL_DB_HOST", "127.0.0.1"), "MariaDB host")
	dbName := fs.String("db-name", envOrDefault("MAIL_DB_NAME", "litecontrol"), "MariaDB database name")
	dbUser := fs.String("db-user", "lc_mail_ro", "MariaDB read-only mail user")
	dbPassword := fs.String("db-password", os.Getenv("MAIL_RO_DB_PASSWORD"), "MariaDB read-only mail password")
	skipPackages := fs.Bool("skip-packages", false, "Skip ClamAV OS package installation")
	skipReconcile := fs.Bool("skip-reconcile", false, "Skip post-enable filter reconcile")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.FilterEnable(context.Background(), mail.FilterEnableOptions{
		Driver:        *driver,
		WithClamAV:    *withClamAV,
		SkipPackages:  *skipPackages,
		SkipReconcile: *skipReconcile,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			_, err := mail.Reconcile(ctx, mail.ReconcileOptions{
				Scope:      scope,
				DBHost:     *dbHost,
				DBName:     *dbName,
				DBUser:     *dbUser,
				DBPassword: *dbPassword,
			})
			return err
		},
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailFilterDemote(args []string) error {
	fs := flag.NewFlagSet("mail filter-demote", flag.ContinueOnError)
	driver := fs.String("driver", "rspamd", "Anti-spam filter driver (rspamd)")
	dbHost := fs.String("db-host", envOrDefault("MAIL_DB_HOST", "127.0.0.1"), "MariaDB host")
	dbName := fs.String("db-name", envOrDefault("MAIL_DB_NAME", "litecontrol"), "MariaDB database name")
	dbUser := fs.String("db-user", "lc_mail_ro", "MariaDB read-only mail user")
	dbPassword := fs.String("db-password", os.Getenv("MAIL_RO_DB_PASSWORD"), "MariaDB read-only mail password")
	skipReconcile := fs.Bool("skip-reconcile", false, "Skip post-demote filter reconcile")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.FilterDemote(context.Background(), mail.FilterDemoteOptions{
		Driver:        *driver,
		SkipReconcile: *skipReconcile,
		ReconcileFunc: func(ctx context.Context, scope string) error {
			_, err := mail.Reconcile(ctx, mail.ReconcileOptions{
				Scope:      scope,
				DBHost:     *dbHost,
				DBName:     *dbName,
				DBUser:     *dbUser,
				DBPassword: *dbPassword,
			})
			return err
		},
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailFilterStatus(args []string) error {
	fs := flag.NewFlagSet("mail filter-status", flag.ContinueOnError)
	driver := fs.String("driver", "rspamd", "Anti-spam filter driver (rspamd)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.FilterStatus(context.Background(), mail.FilterStatusOptions{
		Driver: *driver,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailWebmailInstall(args []string) error {
	fs := flag.NewFlagSet("mail webmail-install", flag.ContinueOnError)
	driver := fs.String("driver", "snappymail", "Webmail driver (snappymail)")
	hostname := fs.String("hostname", "", "Server-level FQDN hostname for webmail (required)")
	lsphpBin := fs.String("lsphp-bin", "", "Path to lsphp binary")
	skipReload := fs.Bool("skip-reload", false, "Skip OpenLiteSpeed reload")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *hostname == "" {
		fs.Usage()
		return fmt.Errorf("--hostname is required")
	}

	res, err := mail.WebmailInstall(context.Background(), mail.WebmailInstallOptions{
		Driver:     *driver,
		Hostname:   *hostname,
		LsphpBin:   *lsphpBin,
		SkipReload: *skipReload,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailWebmailUninstall(args []string) error {
	fs := flag.NewFlagSet("mail webmail-uninstall", flag.ContinueOnError)
	driver := fs.String("driver", "snappymail", "Webmail driver (snappymail)")
	skipReload := fs.Bool("skip-reload", false, "Skip OpenLiteSpeed reload")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.WebmailUninstall(context.Background(), mail.WebmailUninstallOptions{
		Driver:     *driver,
		SkipReload: *skipReload,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func runMailWebmailStatus(args []string) error {
	fs := flag.NewFlagSet("mail webmail-status", flag.ContinueOnError)
	driver := fs.String("driver", "snappymail", "Webmail driver (snappymail)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	res, err := mail.WebmailStatus(context.Background(), mail.WebmailStatusOptions{
		Driver: *driver,
	})
	if err != nil {
		return err
	}

	return json.NewEncoder(os.Stdout).Encode(res)
}

func printMailUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-mail <subcommand> [flags]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Subcommands:")
	fmt.Fprintln(os.Stderr, "  install-core    Install and configure Postfix, Dovecot, Rspamd-Light, postsrsd, and lc-rspamd-redis")
	fmt.Fprintln(os.Stderr, "  uninstall-core  Stop and disable the mail stack (preserves /var/vmail unless --purge-data is set)")
	fmt.Fprintln(os.Stderr, "  reconcile       Idempotently rebuild SNI, DKIM, filter, and SRS configs and reload changed daemons")
	fmt.Fprintln(os.Stderr, "  evict-session   Flush Dovecot auth cache and kick active sessions for an email address")
	fmt.Fprintln(os.Stderr, "  purge-mailbox   Safely remove /var/vmail/<domain>/<user> after path containment validation")
	fmt.Fprintln(os.Stderr, "  quota-usage     Query Dovecot quota usage for a domain and print JSON {email: used_bytes}")
	fmt.Fprintln(os.Stderr, "  dkim-generate   Generate a DKIM keypair via rspamadm and print JSON {public_key_txt, private_key_path, key_sha256}")
	fmt.Fprintln(os.Stderr, "  dkim-remove     Safely unlink a DKIM private key under /var/lib/rspamd/dkim and reconcile DKIM maps")
	fmt.Fprintln(os.Stderr, "  filter-enable   Upgrade Rspamd-Light to full inbound anti-spam (+ optional --with-clamav)")
	fmt.Fprintln(os.Stderr, "  filter-demote   Demote Rspamd back to DKIM-Light + outbound ratelimit without touching DKIM keys")
	fmt.Fprintln(os.Stderr, "  filter-status     Report anti-spam filter mode and running status as JSON")
	fmt.Fprintln(os.Stderr, "  webmail-install   Provision isolated SnappyMail webmail vhost & LSAPI pool on OpenLiteSpeed")
	fmt.Fprintln(os.Stderr, "  webmail-uninstall Remove dedicated SnappyMail OpenLiteSpeed vhost and app directory")
	fmt.Fprintln(os.Stderr, "  webmail-status    Report webmail installation and running status as JSON")
}
