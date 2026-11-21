package mail

import (
	"context"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// DovecotSeries identifies the Dovecot configuration syntax generation.
type DovecotSeries string

const (
	Dovecot23 DovecotSeries = "2.3"
	Dovecot24 DovecotSeries = "2.4"
)

// PasswordScheme represents the preferred Dovecot password hashing scheme.
type PasswordScheme string

const (
	SchemeArgon2ID    PasswordScheme = "ARGON2ID"
	SchemeSHA512Crypt PasswordScheme = "SHA512-CRYPT"
)

// StackCapabilities holds probed mail daemon versions and capabilities.
type StackCapabilities struct {
	PostfixVersion string
	DovecotVersion string
	DovecotSeries  DovecotSeries
	PasswordScheme PasswordScheme
}

var semverRe = regexp.MustCompile(`(\d+)\.(\d+)(?:\.(\d+))?`)

// ParseMajorMinor extracts major and minor version numbers from a version string.
func ParseMajorMinor(raw string) (int, int, error) {
	m := semverRe.FindStringSubmatch(strings.TrimSpace(raw))
	if len(m) < 3 {
		return 0, 0, fmt.Errorf("could not parse version from %q", raw)
	}
	major, err := strconv.Atoi(m[1])
	if err != nil {
		return 0, 0, err
	}
	minor, err := strconv.Atoi(m[2])
	if err != nil {
		return 0, 0, err
	}
	return major, minor, nil
}

// CheckPostfixVersion asserts Postfix >= 3.4 (required for postmap -F and tls_server_sni_maps).
func CheckPostfixVersion(raw string) (string, error) {
	version := strings.TrimSpace(raw)
	major, minor, err := ParseMajorMinor(version)
	if err != nil {
		return "", fmt.Errorf("invalid Postfix version %q: %w", raw, err)
	}
	if major < 3 || (major == 3 && minor < 4) {
		return "", fmt.Errorf("Postfix >= 3.4 is required (found %s)", version)
	}
	return version, nil
}

// DetectDovecotSeries classifies Dovecot 2.3 vs 2.4+ from `dovecot --version` output.
func DetectDovecotSeries(raw string) (string, DovecotSeries, error) {
	version := strings.TrimSpace(raw)
	major, minor, err := ParseMajorMinor(version)
	if err != nil {
		return "", "", fmt.Errorf("invalid Dovecot version %q: %w", raw, err)
	}
	if major > 2 || (major == 2 && minor >= 4) {
		return version, Dovecot24, nil
	}
	if major == 2 && minor == 3 {
		return version, Dovecot23, nil
	}
	return "", "", fmt.Errorf("unsupported Dovecot version %q (supported: 2.3 or 2.4+)", version)
}

// DetectPasswordScheme checks `doveadm pw -l` output for ARGON2ID, falling back to SHA512-CRYPT.
func DetectPasswordScheme(doveadmPwList string) PasswordScheme {
	for _, token := range strings.Fields(strings.ToUpper(doveadmPwList)) {
		if token == "ARGON2ID" {
			return SchemeArgon2ID
		}
	}
	return SchemeSHA512Crypt
}

// ProbeCapabilities inspects installed Postfix and Dovecot binaries.
func ProbeCapabilities(ctx context.Context, runner CommandRunner) (StackCapabilities, error) {
	postfixOut, err := runner.Run(ctx, "postconf", "-h", "mail_version")
	if err != nil {
		return StackCapabilities{}, fmt.Errorf("probe postfix version: %w", err)
	}
	postfixVer, err := CheckPostfixVersion(postfixOut)
	if err != nil {
		return StackCapabilities{}, err
	}

	dovecotOut, err := runner.Run(ctx, "dovecot", "--version")
	if err != nil {
		return StackCapabilities{}, fmt.Errorf("probe dovecot version: %w", err)
	}
	dovecotVer, series, err := DetectDovecotSeries(dovecotOut)
	if err != nil {
		return StackCapabilities{}, err
	}

	pwOut, err := runner.Run(ctx, "doveadm", "pw", "-l")
	if err != nil {
		return StackCapabilities{}, fmt.Errorf("probe doveadm password schemes: %w", err)
	}
	scheme := DetectPasswordScheme(pwOut)

	return StackCapabilities{
		PostfixVersion: postfixVer,
		DovecotVersion: dovecotVer,
		DovecotSeries:  series,
		PasswordScheme: scheme,
	}, nil
}
