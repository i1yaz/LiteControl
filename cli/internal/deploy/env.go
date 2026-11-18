package deploy

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

// EnvOptions holds parameters for `lc-cli deploy env`.
type EnvOptions struct {
	Action    string
	User      string
	Domain    string
	InputFile string
}

// ManageEnv reads or atomically writes `/home/{user}/{domain}/shared/.env`
// after verifying the target does not escape the shared directory via symlinks.
func ManageEnv(opts EnvOptions, stdout io.Writer) error {
	if opts.User == "" || opts.Domain == "" {
		return fmt.Errorf("user and domain are required")
	}

	sharedDir := filepath.Join("/home", opts.User, opts.Domain, "shared")
	envPath := filepath.Join(sharedDir, ".env")

	if err := validateEnvPath(sharedDir, envPath); err != nil {
		return err
	}

	switch opts.Action {
	case "read":
		data, err := os.ReadFile(envPath)
		if err != nil {
			return fmt.Errorf("read %s: %w", envPath, err)
		}
		_, err = stdout.Write(data)
		return err

	case "write":
		if opts.InputFile == "" {
			return fmt.Errorf("--input-file is required for write action")
		}
		data, err := os.ReadFile(opts.InputFile)
		if err != nil {
			return fmt.Errorf("read input file %s: %w", opts.InputFile, err)
		}

		uid, gid, err := lookupUserIDs(opts.User)
		if err != nil {
			return fmt.Errorf("lookup user %s: %w", opts.User, err)
		}

		tmpPath := filepath.Join(sharedDir, ".env.tmp")
		if err := os.WriteFile(tmpPath, data, 0600); err != nil {
			return fmt.Errorf("write temp env file: %w", err)
		}
		if err := os.Chmod(tmpPath, 0600); err != nil {
			_ = os.Remove(tmpPath)
			return fmt.Errorf("chmod temp env file: %w", err)
		}
		if runtime.GOOS != "windows" && uid >= 0 && gid >= 0 {
			if err := os.Chown(tmpPath, uid, gid); err != nil {
				_ = os.Remove(tmpPath)
				return fmt.Errorf("chown temp env file: %w", err)
			}
		}
		if err := os.Rename(tmpPath, envPath); err != nil {
			_ = os.Remove(tmpPath)
			return fmt.Errorf("rename temp env file: %w", err)
		}
		return nil

	default:
		return fmt.Errorf("invalid action %q: must be read or write", opts.Action)
	}
}

func validateEnvPath(sharedDir string, envPath string) error {
	info, err := os.Lstat(envPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("stat %s: %w", envPath, err)
	}

	if info.Mode()&os.ModeSymlink != 0 {
		sharedReal, err := filepath.EvalSymlinks(sharedDir)
		if err != nil {
			return fmt.Errorf("resolve shared directory: %w", err)
		}
		envReal, err := filepath.EvalSymlinks(envPath)
		if err != nil {
			return fmt.Errorf("resolve env symlink: %w", err)
		}
		prefix := sharedReal + string(filepath.Separator)
		if !strings.HasPrefix(envReal, prefix) {
			return fmt.Errorf("env path %s resolves outside shared directory %s", envReal, sharedReal)
		}
	}

	return nil
}
