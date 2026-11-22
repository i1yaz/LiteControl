package main

import (
	"fmt"
	"os"

	"github.com/litecontrol/lc-cli/cmd"
)

func main() {
	if err := cmd.ExecuteMail(os.Args[1:]); err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		os.Exit(1)
	}
}
