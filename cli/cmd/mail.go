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
	case "-h", "--help", "help":
		printMailUsage()
		return nil
	default:
		printMailUsage()
		return fmt.Errorf("unknown mail subcommand: %s", args[0])
	}
}

func runMailInstallCore(args []string) error {
	fs := flag.NewFlagSet("mail install-core", flag.ContinueOnError)
	hostname := fs.String("hostname", "", "Mail server FQDN hostname (required)")
	dbHost := fs.String("db-host", "127.0.0.1", "MariaDB host")
	dbName := fs.String("db-name", "litecontrol", "MariaDB database name")
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

func printMailUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-mail <subcommand> [flags]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Subcommands:")
	fmt.Fprintln(os.Stderr, "  install-core    Install and configure Postfix, Dovecot, Rspamd-Light, postsrsd, and lc-rspamd-redis")
	fmt.Fprintln(os.Stderr, "  uninstall-core  Stop and disable the mail stack (preserves /var/vmail unless --purge-data is set)")
}
