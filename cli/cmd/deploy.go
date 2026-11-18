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

func printDeployUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-cli deploy <subcommand> [flags]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Subcommands:")
	fmt.Fprintln(os.Stderr, "  init    Initialize atomic deployment directories and OpenLiteSpeed config")
}
