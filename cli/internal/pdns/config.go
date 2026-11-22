package pdns

import (
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// OSFamily identifies the Linux distribution family for PowerDNS configuration paths.
type OSFamily string

const (
	OSFamilyDebian OSFamily = "debian"
	OSFamilyRHEL   OSFamily = "rhel"
)

// Paths holds the resolved configuration file and service paths for PowerDNS.
type Paths struct {
	OSFamily       OSFamily `json:"distro_family"`
	MainConfigPath string   `json:"config_path"`
	DropInDir      string   `json:"drop_in_dir"`
	DropInPath     string   `json:"drop_in_path"`
	ServiceName    string   `json:"service_name"`
}

// ConfigPayload represents the JSON input for `lc-cli pdns apply-config --payload <json>`.
type ConfigPayload struct {
	APIKey              string `json:"api_key"`
	WebserverAddress    string `json:"webserver_address"`
	WebserverPort       int    `json:"webserver_port"`
	WebserverAllowFrom  string `json:"webserver_allow_from"`
	WebserverPassword   string `json:"webserver_password,omitempty"`
	Primary             bool   `json:"primary"`
	Secondary           bool   `json:"secondary"`
	Autosecondary       bool   `json:"autosecondary"`
	AllowAxfrIPs        string `json:"allow_axfr_ips,omitempty"`
	AlsoNotify          string `json:"also_notify,omitempty"`
	AllowUnsignedNotify *bool  `json:"allow_unsigned_notify,omitempty"`
}

var hex64Pattern = regexp.MustCompile(`^[0-9a-fA-F]{32,128}$`)

// ResolvePaths determines PowerDNS paths based on `/etc/os-release` content or existing directories.
func ResolvePaths(osReleasePath string, rootPrefix string) (Paths, error) {
	family, err := DetectOSFamily(osReleasePath, rootPrefix)
	if err != nil {
		return Paths{}, err
	}
	return PathsForFamily(family, rootPrefix), nil
}

// PathsForFamily returns canonical PowerDNS paths for a given OSFamily, optionally prefixed by rootPrefix for testing.
func PathsForFamily(family OSFamily, rootPrefix string) Paths {
	var mainConf, dropInDir string
	switch family {
	case OSFamilyRHEL:
		mainConf = "/etc/pdns/pdns.conf"
		dropInDir = "/etc/pdns/pdns.d"
	default:
		family = OSFamilyDebian
		mainConf = "/etc/powerdns/pdns.conf"
		dropInDir = "/etc/powerdns/pdns.d"
	}

	if rootPrefix != "" {
		mainConf = filepath.ToSlash(filepath.Join(rootPrefix, filepath.FromSlash(mainConf)))
		dropInDir = filepath.ToSlash(filepath.Join(rootPrefix, filepath.FromSlash(dropInDir)))
	}

	dropInPath := filepath.ToSlash(filepath.Join(filepath.FromSlash(dropInDir), "99-litecontrol.conf"))

	return Paths{
		OSFamily:       family,
		MainConfigPath: mainConf,
		DropInDir:      dropInDir,
		DropInPath:     dropInPath,
		ServiceName:    "pdns",
	}
}

// DetectOSFamily inspects `/etc/os-release` (or fallback filesystem directories) to identify Debian/Ubuntu vs RHEL/Rocky/Alma.
func DetectOSFamily(osReleasePath string, rootPrefix string) (OSFamily, error) {
	if osReleasePath == "" {
		osReleasePath = "/etc/os-release"
		if rootPrefix != "" {
			osReleasePath = filepath.Join(rootPrefix, "etc", "os-release")
		}
	}

	data, err := os.ReadFile(osReleasePath)
	if err == nil {
		family, parseErr := ParseOSReleaseFamily(string(data))
		if parseErr == nil {
			return family, nil
		}
	}

	// Fallback: check if /etc/powerdns or /etc/pdns exists
	debDir := "/etc/powerdns"
	rhelDir := "/etc/pdns"
	if rootPrefix != "" {
		debDir = filepath.Join(rootPrefix, "etc", "powerdns")
		rhelDir = filepath.Join(rootPrefix, "etc", "pdns")
	}

	if fi, statErr := os.Stat(rhelDir); statErr == nil && fi.IsDir() {
		return OSFamilyRHEL, nil
	}
	if fi, statErr := os.Stat(debDir); statErr == nil && fi.IsDir() {
		return OSFamilyDebian, nil
	}

	if err != nil {
		return "", fmt.Errorf("detect OS family from %s: %w", osReleasePath, err)
	}
	return OSFamilyDebian, nil
}

// ParseOSReleaseFamily parses `/etc/os-release` key-value lines into an OSFamily.
func ParseOSReleaseFamily(content string) (OSFamily, error) {
	var id, idLike string
	for _, rawLine := range strings.Split(content, "\n") {
		line := strings.TrimSpace(rawLine)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		v = strings.Trim(strings.TrimSpace(v), `"'`)
		switch k {
		case "ID":
			id = strings.ToLower(v)
		case "ID_LIKE":
			idLike = strings.ToLower(v)
		}
	}

	combined := id + " " + idLike
	for _, token := range strings.Fields(combined) {
		switch token {
		case "debian", "ubuntu":
			return OSFamilyDebian, nil
		case "rhel", "fedora", "centos", "rocky", "almalinux", "ol":
			return OSFamilyRHEL, nil
		}
	}

	return "", fmt.Errorf("unsupported or unrecognized OS in os-release (ID=%q, ID_LIKE=%q)", id, idLike)
}

// ApplyDefaults fills in safe loopback defaults when optional fields are omitted.
func (p *ConfigPayload) ApplyDefaults() {
	if strings.TrimSpace(p.WebserverAddress) == "" {
		p.WebserverAddress = "127.0.0.1"
	}
	if p.WebserverPort == 0 {
		p.WebserverPort = 8081
	}
	if strings.TrimSpace(p.WebserverAllowFrom) == "" && isLoopbackAddress(p.WebserverAddress) {
		p.WebserverAllowFrom = "127.0.0.1,::1"
	}
}

// Validate checks ConfigPayload against security and syntax rules before writing anything to disk.
func (p ConfigPayload) Validate() error {
	if strings.TrimSpace(p.APIKey) == "" {
		return fmt.Errorf("api_key is required")
	}
	if strings.ContainsAny(p.APIKey, "\r\n\t ") || !hex64Pattern.MatchString(p.APIKey) {
		return fmt.Errorf("api_key must be a 32-to-128 character hexadecimal secret without whitespace")
	}

	addr := strings.TrimSpace(p.WebserverAddress)
	ip := net.ParseIP(addr)
	if ip == nil {
		return fmt.Errorf("webserver_address %q is not a valid IP address", p.WebserverAddress)
	}

	if p.WebserverPort < 1 || p.WebserverPort > 65535 {
		return fmt.Errorf("webserver_port %d must be between 1 and 65535", p.WebserverPort)
	}

	allowList := splitCSV(p.WebserverAllowFrom)
	if len(allowList) == 0 {
		return fmt.Errorf("webserver_allow_from is required")
	}

	for _, entry := range allowList {
		if err := validateIPOrCIDR(entry); err != nil {
			return fmt.Errorf("invalid entry %q in webserver_allow_from: %w", entry, err)
		}
	}

	// Non-loopback bind address security guardrails
	if !ip.IsLoopback() {
		for _, entry := range allowList {
			if entry == "0.0.0.0/0" || entry == "::/0" {
				return fmt.Errorf("webserver_allow_from cannot contain unrestricted %q when webserver_address is non-loopback (%s)", entry, addr)
			}
		}
		if strings.TrimSpace(p.WebserverPassword) == "" {
			return fmt.Errorf("webserver_password is required when webserver_address is non-loopback (%s)", addr)
		}
	}

	if strings.ContainsAny(p.WebserverPassword, "\r\n") {
		return fmt.Errorf("webserver_password cannot contain newline characters")
	}

	for _, entry := range splitCSV(p.AllowAxfrIPs) {
		if err := validateIPOrCIDR(entry); err != nil {
			return fmt.Errorf("invalid entry %q in allow_axfr_ips: %w", entry, err)
		}
	}

	for _, entry := range splitCSV(p.AlsoNotify) {
		if err := validateIPWithOptionalPort(entry); err != nil {
			return fmt.Errorf("invalid entry %q in also_notify: %w", entry, err)
		}
	}

	return nil
}

// SerializeDropIn renders the deterministic `99-litecontrol.conf` content.
func SerializeDropIn(p ConfigPayload) (string, error) {
	p.ApplyDefaults()
	if err := p.Validate(); err != nil {
		return "", err
	}

	var b strings.Builder
	b.WriteString("# Managed by LiteControl Panel — DO NOT EDIT MANUALLY\n")
	b.WriteString("# HTTP API & Webserver (loopback-only by default)\n")
	b.WriteString("api=yes\n")
	b.WriteString(fmt.Sprintf("api-key=%s\n", strings.TrimSpace(p.APIKey)))
	b.WriteString("webserver=yes\n")
	b.WriteString(fmt.Sprintf("webserver-address=%s\n", strings.TrimSpace(p.WebserverAddress)))
	b.WriteString(fmt.Sprintf("webserver-port=%d\n", p.WebserverPort))
	b.WriteString(fmt.Sprintf("webserver-allow-from=%s\n", strings.Join(splitCSV(p.WebserverAllowFrom), ",")))
	if strings.TrimSpace(p.WebserverPassword) != "" {
		b.WriteString(fmt.Sprintf("webserver-password=%s\n", strings.TrimSpace(p.WebserverPassword)))
	}

	b.WriteString("\n# Role Capabilities\n")
	b.WriteString(fmt.Sprintf("primary=%s\n", boolYesNo(p.Primary)))
	b.WriteString(fmt.Sprintf("secondary=%s\n", boolYesNo(p.Secondary)))
	b.WriteString(fmt.Sprintf("autosecondary=%s\n", boolYesNo(p.Autosecondary)))

	if axfr := splitCSV(p.AllowAxfrIPs); len(axfr) > 0 {
		b.WriteString(fmt.Sprintf("allow-axfr-ips=%s\n", strings.Join(axfr, ",")))
	}
	if notify := splitCSV(p.AlsoNotify); len(notify) > 0 {
		b.WriteString(fmt.Sprintf("also-notify=%s\n", strings.Join(notify, ",")))
	}
	if p.AllowUnsignedNotify != nil {
		b.WriteString(fmt.Sprintf("allow-unsigned-notify=%s\n", boolYesNo(*p.AllowUnsignedNotify)))
	}

	return b.String(), nil
}

// EnsureIncludeDir ensures `include-dir=<dropInDir>` is active in the main `pdns.conf` content.
// It returns the updated content and a boolean indicating whether the content was modified.
func EnsureIncludeDir(mainConfContent string, dropInDir string) (string, bool) {
	canonicalDir := filepath.ToSlash(strings.TrimSpace(dropInDir))
	targetLine := "include-dir=" + canonicalDir

	lines := strings.Split(strings.ReplaceAll(mainConfContent, "\r\n", "\n"), "\n")
	foundActive := false
	modified := false

	for i, raw := range lines {
		trimmed := strings.TrimSpace(raw)
		if strings.HasPrefix(trimmed, "#") {
			uncommented := strings.TrimSpace(strings.TrimLeft(trimmed, "#"))
			if strings.HasPrefix(uncommented, "include-dir=") && !foundActive {
				lines[i] = targetLine
				foundActive = true
				modified = true
			}
			continue
		}

		if strings.HasPrefix(trimmed, "include-dir=") {
			if !foundActive {
				if trimmed != targetLine {
					lines[i] = targetLine
					modified = true
				}
				foundActive = true
			}
		}
	}

	if !foundActive {
		if len(lines) > 0 && lines[len(lines)-1] != "" {
			lines = append(lines, targetLine, "")
		} else if len(lines) > 0 && lines[len(lines)-1] == "" {
			lines[len(lines)-1] = targetLine
			lines = append(lines, "")
		} else {
			lines = []string{targetLine, ""}
		}
		modified = true
	}

	return strings.Join(lines, "\n"), modified
}

func boolYesNo(v bool) string {
	if v {
		return "yes"
	}
	return "no"
}

func isLoopbackAddress(addr string) bool {
	ip := net.ParseIP(strings.TrimSpace(addr))
	return ip != nil && ip.IsLoopback()
}

func splitCSV(raw string) []string {
	if strings.TrimSpace(raw) == "" {
		return nil
	}
	parts := strings.Split(raw, ",")
	var out []string
	for _, p := range parts {
		trimmed := strings.TrimSpace(p)
		if trimmed != "" {
			out = append(out, trimmed)
		}
	}
	return out
}

func validateIPOrCIDR(entry string) error {
	if strings.Contains(entry, "/") {
		if _, _, err := net.ParseCIDR(entry); err != nil {
			return err
		}
		return nil
	}
	if ip := net.ParseIP(entry); ip == nil {
		return fmt.Errorf("invalid IP or CIDR %q", entry)
	}
	return nil
}

func validateIPWithOptionalPort(entry string) error {
	if ip := net.ParseIP(entry); ip != nil {
		return nil
	}
	host, portStr, err := net.SplitHostPort(entry)
	if err != nil {
		return fmt.Errorf("invalid IP or IP:port %q", entry)
	}
	if ip := net.ParseIP(host); ip == nil {
		return fmt.Errorf("invalid IP %q", host)
	}
	var port int
	if _, err := fmt.Sscanf(portStr, "%d", &port); err != nil || port < 1 || port > 65535 {
		return fmt.Errorf("invalid port %q", portStr)
	}
	return nil
}
