package main

import (
	"fmt"
	"os"

	"github.com/litecontrol/lc-cli/cmd"
)

func main() {
	args := os.Args[1:]
	if len(args) > 0 && args[0] == "--json" {
		args = args[1:]
	}

	if len(args) < 1 {
		printUsage()
		os.Exit(1)
	}

	switch args[0] {
	case "deploy":
		if err := cmd.ExecuteDeploy(args[1:]); err != nil {
			fmt.Fprintf(os.Stderr, "error: %v\n", err)
			os.Exit(1)
		}
	case "pdns":
		if err := cmd.ExecutePdns(args[1:], os.Stdout); err != nil {
			fmt.Fprintf(os.Stderr, "error: %v\n", err)
			os.Exit(1)
		}
	case "-h", "--help", "help":
		printUsage()
	default:
		fmt.Fprintf(os.Stderr, "unknown command: %s\n", args[0])
		printUsage()
		os.Exit(1)
	}
}

func printUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lc-cli [--json] <command> [arguments]")
	fmt.Fprintln(os.Stderr, "")
	fmt.Fprintln(os.Stderr, "Commands:")
	fmt.Fprintln(os.Stderr, "  deploy    Manage zero-downtime atomic deployments")
	fmt.Fprintln(os.Stderr, "  pdns      Manage PowerDNS daemon config, pre-flight checks, and service lifecycle")
}
