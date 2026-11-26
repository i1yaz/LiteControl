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
	case "extract-tarball":
		return runDeployExtractTarball(args[1:])
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
	fetchPayloadStdin := fs.Bool("fetch-payload-stdin", false, "Read versioned provider fetch payload JSON from stdin")
	capabilities := fs.Bool("capabilities", false, "Print supported deploy runner capabilities as JSON and exit")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *capabilities {
		return json.NewEncoder(os.Stdout).Encode(map[string]any{
			"stdin_fetch_payload": true,
			"schema_versions":     []int{deploy.SupportedFetchSchemaVersion},
		})
	}

	if *specPath == "" {
		fs.Usage()
		return fmt.Errorf("--spec is required")
	}

	_ = deploy.SweepStaleAskpassDirs("", deploy.DefaultStaleAskpassAge)

	var fetchPayload *deploy.FetchPayload
	if *fetchPayloadStdin {
		parsed, err := deploy.ReadFetchPayload(os.Stdin, deploy.DefaultStdinReadTimeout)
		if err != nil {
			return err
		}
		fetchPayload = &parsed
	}

	result, err := deploy.RunDeployWithFetch(context.Background(), *specPath, fetchPayload, os.Stderr)
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

func runDeployExtractTarball(args []string) error {
	fs := flag.NewFlagSet("deploy extract-tarball", flag.ContinueOnError)
	archivePath := fs.String("archive", "", "Path to gzipped tar archive (required)")
	destDir := fs.String("dest", "", "Destination release directory (required)")
	stripComponents := fs.Int("strip-components", 1, "Leading path components to strip")
	maxBytes := fs.Int64("max-bytes", deploy.DefaultMaxDecompressedBytes, "Maximum decompressed bytes")
	maxFiles := fs.Int("max-files", deploy.DefaultMaxExtractedFiles, "Maximum extracted file count")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *archivePath == "" || *destDir == "" {
		return fmt.Errorf("--archive and --dest are required")
	}

	f, err := os.Open(*archivePath)
	if err != nil {
		return fmt.Errorf("open archive %s: %w", *archivePath, err)
	}
	defer f.Close()

	return deploy.ExtractTarGzSafely(f, *destDir, deploy.TarExtractOptions{
		StripComponents:      *stripComponents,
		MaxDecompressedBytes: *maxBytes,
		MaxFiles:             *maxFiles,
	})
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
