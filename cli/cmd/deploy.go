package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"

	"github.com/litecontrol/lc-cli/internal/deploy"
)

// ExecuteDeploy handles `lc-cli deploy <subcommand> [flags]`.
func ExecuteDeploy(args []string) error {
	if len(args) == 0 {
		printDeployUsage()
		return fmt.Errorf("missing deploy subcommand")
	}

	switch args[0] {
	case "init":
		return runDeployInit(args[1:])
	case "run":
		return runDeployRun(args[1:])
	case "rollback":
		return runDeployRollback(args[1:])
	case "env":
		return runDeployEnv(args[1:])
	case "-h", "--help", "help":
		printDeployUsage()
		return nil
	default:
		printDeployUsage()
		return fmt.Errorf("unknown deploy subcommand: %s", args[0])
	}
}

func runDeployInit(args []string) error {
	fs := flag.NewFlagSet("deploy init", flag.ContinueOnError)
	user := fs.String("user", "", "Tenant Linux username (required)")
	domain := fs.String("domain", "", "Domain name (required)")
	webSubpath := fs.String("web-subpath", "public", "Web document root subpath inside release (e.g. public)")
	seedShared := fs.Bool("seed-shared", true, "Seed shared/ from existing public_html directory")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *user == "" || *domain == "" {
		fs.Usage()
		return fmt.Errorf("--user and --domain are required")
	}

	return deploy.InitDomain(deploy.InitOptions{
		User:       *user,
		Domain:     *domain,
		WebSubpath: *webSubpath,
		SeedShared: *seedShared,
	})
}

func runDeployRun(args []string) error {
	fs := flag.NewFlagSet("deploy run", flag.ContinueOnError)
	specPath := fs.String("spec", "", "Path to deploy_{release_id}.json spec file (required)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *specPath == "" {
		fs.Usage()
		return fmt.Errorf("--spec is required")
	}

	result, err := deploy.RunDeploy(context.Background(), *specPath, os.Stderr)
	if result.Status != "" {
		if encErr := json.NewEncoder(os.Stdout).Encode(result); encErr != nil {
			return encErr
		}
	}

	if errors.Is(err, deploy.ErrRolledBack) {
		os.Exit(2)
	}

	return err
}

func runDeployRollback(args []string) error {
	fs := flag.NewFlagSet("deploy rollback", flag.ContinueOnError)
	user := fs.String("user", "", "Tenant Linux username (required)")
	domain := fs.String("domain", "", "Domain name (required)")
	release := fs.String("release", "", "Target release ID to roll back to (required)")
	phpBin := fs.String("php", "/usr/local/lsws/lsphp83/bin/php", "PHP binary path for artisan queue:restart")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *user == "" || *domain == "" || *release == "" {
		fs.Usage()
		return fmt.Errorf("--user, --domain, and --release are required")
	}

	return deploy.RollbackDomain(context.Background(), deploy.RollbackOptions{
		User:      *user,
		Domain:    *domain,
		Release:   *release,
		PHPBinary: *phpBin,
	})
}

func runDeployEnv(args []string) error {
	fs := flag.NewFlagSet("deploy env", flag.ContinueOnError)
	action := fs.String("action", "", "Action to perform: read or write (required)")
	user := fs.String("user", "", "Tenant Linux username (required)")
	domain := fs.String("domain", "", "Domain name (required)")
	inputFile := fs.String("input-file", "", "Path to input file for write action")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *action == "" || *user == "" || *domain == "" {
		fs.Usage()
		return fmt.Errorf("--action, --user, and --domain are required")
	}

	return deploy.ManageEnv(deploy.EnvOptions{
		Action:    *action,
		User:      *user,
		Domain:    *domain,
		InputFile: *inputFile,
	}, os.Stdout)
}

func printDeployUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-cli deploy <subcommand> [flags]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Subcommands:")
	fmt.Fprintln(os.Stderr, "  init      Initialize atomic deployment directories and OpenLiteSpeed config")
	fmt.Fprintln(os.Stderr, "  run       Execute an atomic deployment from a JSON spec file")
	fmt.Fprintln(os.Stderr, "  rollback  Atomically switch current symlink to a retained release")
	fmt.Fprintln(os.Stderr, "  env       Read or atomically write shared/.env")
}
