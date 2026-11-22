package cmd

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"

	"github.com/litecontrol/lc-cli/internal/pdns"
)

type cliEnvelope struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    any    `json:"data"`
}

// ExecutePdns handles `lc-cli pdns <subcommand> [flags]`.
func ExecutePdns(args []string, stdout io.Writer) error {
	if stdout == nil {
		stdout = os.Stdout
	}
	if len(args) == 0 {
		printPdnsUsage()
		return fmt.Errorf("missing pdns subcommand")
	}

	switch args[0] {
	case "status":
		return runPdnsStatus(args[1:], stdout)
	case "apply-config":
		return runPdnsApplyConfig(args[1:], stdout)
	case "-h", "--help", "help":
		printPdnsUsage()
		return nil
	default:
		printPdnsUsage()
		return fmt.Errorf("unknown pdns subcommand: %s", args[0])
	}
}

func runPdnsStatus(args []string, stdout io.Writer) error {
	fs := flag.NewFlagSet("pdns status", flag.ContinueOnError)
	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	mgr := pdns.NewManager()
	res, err := mgr.Status(context.Background())
	if err != nil {
		_ = writeJSONEnvelope(stdout, "error", err.Error(), nil)
		return err
	}

	return writeJSONEnvelope(stdout, "success", "PowerDNS status retrieved.", res)
}

func runPdnsApplyConfig(args []string, stdout io.Writer) error {
	fs := flag.NewFlagSet("pdns apply-config", flag.ContinueOnError)
	payloadJSON := fs.String("payload", "", "JSON configuration payload for 99-litecontrol.conf (required)")

	if err := fs.Parse(args); err != nil {
		if err == flag.ErrHelp {
			return nil
		}
		return err
	}

	if *payloadJSON == "" {
		fs.Usage()
		err := fmt.Errorf("--payload is required")
		_ = writeJSONEnvelope(stdout, "error", err.Error(), nil)
		return err
	}

	var payload pdns.ConfigPayload
	if err := json.Unmarshal([]byte(*payloadJSON), &payload); err != nil {
		wrapErr := fmt.Errorf("invalid --payload JSON: %w", err)
		_ = writeJSONEnvelope(stdout, "error", wrapErr.Error(), nil)
		return wrapErr
	}

	mgr := pdns.NewManager()
	res, err := mgr.ApplyConfig(context.Background(), payload)
	if err != nil {
		_ = writeJSONEnvelope(stdout, "error", err.Error(), nil)
		return err
	}

	return writeJSONEnvelope(stdout, "success", "PowerDNS configuration validated and applied.", res)
}

func writeJSONEnvelope(w io.Writer, status string, message string, data any) error {
	return json.NewEncoder(w).Encode(cliEnvelope{
		Status:  status,
		Message: message,
		Data:    data,
	})
}

func printPdnsUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-cli pdns <subcommand> [flags]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Subcommands:")
	fmt.Fprintln(os.Stderr, "  status        Return JSON with distro family, config paths, systemd state, and port 53 check")
	fmt.Fprintln(os.Stderr, "  apply-config  Atomically validate and apply 99-litecontrol.conf drop-in and restart pdns")
}
