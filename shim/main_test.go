package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestIsValidVersion(t *testing.T) {
	valid := []string{"8.3", "7.4", "5.6", "9.0"}
	for _, v := range valid {
		if !isValidVersion(v) {
			t.Errorf("expected isValidVersion(%q) to be true", v)
		}
	}

	invalid := []string{"", "83", "8.3.1", "../", "8.3;id", "4.9", "8.a"}
	for _, v := range invalid {
		if isValidVersion(v) {
			t.Errorf("expected isValidVersion(%q) to be false", v)
		}
	}
}

func TestDetectTargetScriptDir(t *testing.T) {
	tmpDir := t.TempDir()
	siteDir := filepath.Join(tmpDir, "home", "u1", "site")
	if err := os.MkdirAll(siteDir, 0o755); err != nil {
		t.Fatal(err)
	}

	artisanPath := filepath.Join(siteDir, "artisan")
	cronPath := filepath.Join(siteDir, "cron.php")

	if got := detectTargetScriptDir([]string{artisanPath, "schedule:run"}); got != siteDir {
		t.Errorf("expected %q, got %q", siteDir, got)
	}

	if got := detectTargetScriptDir([]string{"-d", "memory_limit=512M", "-f", cronPath}); got != siteDir {
		t.Errorf("expected %q, got %q", siteDir, got)
	}

	if got := detectTargetScriptDir([]string{"-r", "echo PHP_VERSION;"}); got != "" {
		t.Errorf("expected empty string for -r, got %q", got)
	}

	if got := detectTargetScriptDir([]string{"-v"}); got != "" {
		t.Errorf("expected empty string for -v, got %q", got)
	}
}

func TestResolutionPriorityOrder(t *testing.T) {
	tmpDir := t.TempDir()
	lswsDir := filepath.Join(tmpDir, "lsws")
	configDir := filepath.Join(tmpDir, "etc", "lc-panel", "php-cli")
	usersDir := filepath.Join(configDir, "users")
	homeDir := filepath.Join(tmpDir, "home", "u1")
	testSiteDir := filepath.Join(tmpDir, "test-site")

	for _, d := range []string{usersDir, homeDir, testSiteDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}

	// Create mock lsphp binaries for 8.1, 8.2, 8.3
	for _, ver := range []string{"81", "82", "83"} {
		binDir := filepath.Join(lswsDir, "lsphp"+ver, "bin")
		if err := os.MkdirAll(binDir, 0o755); err != nil {
			t.Fatal(err)
		}
		binPath := filepath.Join(binDir, "php")
		if err := os.WriteFile(binPath, []byte("#!/bin/sh\n"), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	if err := os.WriteFile(filepath.Join(configDir, "default"), []byte("8.3\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(usersDir, "u1"), []byte("8.3\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(testSiteDir, ".php-version"), []byte("8.2\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	// 1. LC_PHP_VERSION=8.1 beats .php-version (8.2) and user config (8.3)
	cfg := shimConfig{
		configDir:   configDir,
		lswsBaseDir: lswsDir,
		homeDir:     homeDir,
		cwd:         testSiteDir,
		username:    "u1",
		envVersion:  "8.1",
	}
	bin, ok := resolveBinary("php", []string{filepath.Join(testSiteDir, "artisan")}, cfg)
	if !ok || bin != filepath.Join(lswsDir, "lsphp81", "bin", "php") {
		t.Fatalf("expected lsphp81, got %q (ok=%v)", bin, ok)
	}

	// 2. Running php /tmp/test-site/artisan from /home/u1 resolves test-site/.php-version (8.2)
	cfg.envVersion = ""
	cfg.cwd = homeDir
	bin, ok = resolveBinary("php", []string{filepath.Join(testSiteDir, "artisan")}, cfg)
	if !ok || bin != filepath.Join(lswsDir, "lsphp82", "bin", "php") {
		t.Fatalf("expected lsphp82, got %q (ok=%v)", bin, ok)
	}

	// 3. Oversized (>16 bytes) .php-version is ignored and falls back to user config (8.3)
	oversizedDir := filepath.Join(tmpDir, "oversized")
	if err := os.MkdirAll(oversizedDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(oversizedDir, ".php-version"), []byte("8.2-this-is-way-too-long"), 0o644); err != nil {
		t.Fatal(err)
	}
	bin, ok = resolveBinary("php", []string{filepath.Join(oversizedDir, "index.php")}, cfg)
	if !ok || bin != filepath.Join(lswsDir, "lsphp83", "bin", "php") {
		t.Fatalf("expected fallback to lsphp83 when .php-version > 16 bytes, got %q (ok=%v)", bin, ok)
	}

	// 4. If user's configured lsphp81 is missing, falls back to default (8.3) without crashing
	if err := os.WriteFile(filepath.Join(usersDir, "u1"), []byte("8.1\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(filepath.Join(lswsDir, "lsphp81")); err != nil {
		t.Fatal(err)
	}
	bin, ok = resolveBinary("php", []string{"-v"}, cfg)
	if !ok || bin != filepath.Join(lswsDir, "lsphp83", "bin", "php") {
		t.Fatalf("expected fallback to default lsphp83, got %q (ok=%v)", bin, ok)
	}
}
