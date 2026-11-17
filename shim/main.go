package main

import (
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"strings"
	"syscall"
)

const (
	defaultConfigDir   = "/etc/lc-panel/php-cli"
	defaultLswsBaseDir = "/usr/local/lsws"
	maxWalkDepth       = 10
)

var allowedTools = map[string]bool{
	"php":        true,
	"phar":       true,
	"phar.phar":  true,
	"phpize":     true,
	"php-config": true,
	"pecl":       true,
	"pear":       true,
}

var fallbackVersions = []string{"8.4", "8.3", "8.2", "8.1", "8.0", "7.4"}

type shimConfig struct {
	configDir   string
	lswsBaseDir string
	homeDir     string
	cwd         string
	username    string
	envVersion  string
}

func defaultShimConfig() shimConfig {
	cwd, _ := os.Getwd()
	username := ""
	if u, err := user.Current(); err == nil {
		username = u.Username
	}
	return shimConfig{
		configDir:   defaultConfigDir,
		lswsBaseDir: defaultLswsBaseDir,
		homeDir:     os.Getenv("HOME"),
		cwd:         cwd,
		username:    username,
		envVersion:  os.Getenv("LC_PHP_VERSION"),
	}
}

func main() {
	tool := filepath.Base(os.Args[0])
	if !allowedTools[tool] {
		fmt.Fprintf(os.Stderr, "lc-php-shim: unauthorized binary invocation %q\n", tool)
		os.Exit(1)
	}

	targetBin, ok := resolveBinary(tool, os.Args[1:], defaultShimConfig())
	if !ok {
		fmt.Fprintf(os.Stderr, "lc-php-shim: no executable OpenLiteSpeed %s binary found under %s\n", tool, defaultLswsBaseDir)
		os.Exit(127)
	}

	argv := append([]string{targetBin}, os.Args[1:]...)
	if err := syscall.Exec(targetBin, argv, os.Environ()); err != nil {
		fmt.Fprintf(os.Stderr, "lc-php-shim: failed to exec %s: %v\n", targetBin, err)
		os.Exit(127)
	}
}

func resolveBinary(tool string, args []string, cfg shimConfig) (string, bool) {
	scriptDir := detectTargetScriptDir(args)

	candidates := []string{
		cfg.envVersion,
		findDotPhpVersion(scriptDir, cfg.homeDir),
		findDotPhpVersion(cfg.cwd, cfg.homeDir),
		readUserVersion(cfg.configDir, cfg.username),
		readSmallFile(filepath.Join(cfg.configDir, "default")),
	}
	candidates = append(candidates, fallbackVersions...)

	for _, ver := range candidates {
		if !isValidVersion(ver) {
			continue
		}
		// Map "8.3" -> "lsphp83" for OpenLiteSpeed directory layout
		lsVer := string([]byte{ver[0], ver[2]})
		bin := filepath.Join(cfg.lswsBaseDir, "lsphp"+lsVer, "bin", tool)
		if isExecutable(bin) {
			return bin, true
		}
	}

	return "", false
}

// isValidVersion strictly enforces a 3-byte "X.Y" numeric format to prevent path traversal.
func isValidVersion(v string) bool {
	return len(v) == 3 && v[0] >= '5' && v[0] <= '9' && v[1] == '.' && v[2] >= '0' && v[2] <= '9'
}

// detectTargetScriptDir inspects CLI arguments to find the directory of the target PHP script
// (e.g. `php /home/user/app/artisan schedule:run` or `php -f /home/user/app/cron.php`).
func detectTargetScriptDir(args []string) string {
	for i := 0; i < len(args); i++ {
		arg := args[i]

		if arg == "--" {
			if i+1 < len(args) {
				return resolveFileDir(args[i+1])
			}
			return ""
		}

		// Flags that execute inline code or info without a target script file
		switch arg {
		case "-r", "-a", "-v", "-i", "-m", "--version", "--ini", "--modules":
			return ""
		case "-f":
			if i+1 < len(args) {
				return resolveFileDir(args[i+1])
			}
			return ""
		case "-c", "-d", "-z", "-S", "-t", "-B", "-R", "-F", "-E":
			// These flags consume the next argument as a parameter value
			i++
			continue
		}

		if strings.HasPrefix(arg, "-") {
			continue
		}

		// First non-flag argument is the target PHP script
		return resolveFileDir(arg)
	}
	return ""
}

func resolveFileDir(scriptPath string) string {
	abs, err := filepath.Abs(scriptPath)
	if err != nil {
		return ""
	}
	dir := filepath.Dir(abs)
	if fi, err := os.Stat(dir); err == nil && fi.IsDir() {
		return dir
	}
	return ""
}

func findDotPhpVersion(startDir, home string) string {
	if startDir == "" {
		return ""
	}
	dir := filepath.Clean(startDir)

	for i := 0; i < maxWalkDepth; i++ {
		if v := readSmallFile(filepath.Join(dir, ".php-version")); isValidVersion(v) {
			return v
		}
		if dir == home || dir == "/" || dir == "." {
			break
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	return ""
}

func readUserVersion(configDir, username string) string {
	if username == "" {
		return ""
	}
	if strings.ContainsRune(username, '/') || strings.ContainsRune(username, '\\') || strings.ContainsRune(username, '.') {
		return ""
	}
	return readSmallFile(filepath.Join(configDir, "users", username))
}

func readSmallFile(path string) string {
	fi, err := os.Lstat(path)
	if err != nil || !fi.Mode().IsRegular() || fi.Size() > 16 {
		return ""
	}
	f, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()

	buf := make([]byte, 16)
	n, err := f.Read(buf)
	if err != nil && err != io.EOF {
		return ""
	}
	return strings.TrimSpace(string(buf[:n]))
}

func isExecutable(path string) bool {
	fi, err := os.Stat(path)
	if err != nil || fi.IsDir() {
		return false
	}
	// On Unix check executable bit; on Windows any regular file is treated as executable for tests.
	if os.PathSeparator == '\\' {
		return true
	}
	return fi.Mode()&0111 != 0
}
