package pdns

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// Port53Listener describes a socket bound to port 53 discovered via `ss -tulnp`.
type Port53Listener struct {
	Protocol string `json:"protocol"`
	LocalIP  string `json:"local_ip"`
	Port     int    `json:"port"`
	Process  string `json:"process"`
	PID      int    `json:"pid,omitempty"`
}

// Port53Check summarizes port 53 ownership and any conflicting daemons.
type Port53Check struct {
	Owner        string           `json:"owner"`
	Conflict     bool             `json:"conflict"`
	ConflictInfo string           `json:"conflict_info,omitempty"`
	Listeners    []Port53Listener `json:"listeners"`
}

var ssProcessPattern = regexp.MustCompile(`users:\(\("([^"]+)",pid=(\d+)`)

// ParsePort53Listeners parses `ss -tulnp` output and checks for non-PowerDNS port 53 conflicts.
// systemd-resolved's stub listener on `127.0.0.53:53` or `127.0.0.54:53` is flagged as a conflict
// if PowerDNS binds wildcard (`0.0.0.0` / `::`) or the same IP.
func ParsePort53Listeners(ssOutput string) Port53Check {
	var listeners []Port53Listener
	seen := make(map[string]bool)

	for _, rawLine := range strings.Split(ssOutput, "\n") {
		line := strings.TrimSpace(rawLine)
		if line == "" || strings.HasPrefix(line, "Netid") || strings.HasPrefix(line, "State") {
			continue
		}

		fields := strings.Fields(line)
		if len(fields) < 5 {
			continue
		}

		proto := strings.ToLower(fields[0])
		if proto != "tcp" && proto != "udp" {
			continue
		}

		localAddr := fields[4]
		ipPart, portNum, ok := parseSSLocalAddress(localAddr)
		if !ok || portNum != 53 {
			continue
		}

		procName := "unknown"
		pid := 0
		if matches := ssProcessPattern.FindStringSubmatch(line); len(matches) == 3 {
			procName = matches[1]
			pid, _ = strconv.Atoi(matches[2])
		}

		key := fmt.Sprintf("%s|%s|%d|%s|%d", proto, ipPart, portNum, procName, pid)
		if seen[key] {
			continue
		}
		seen[key] = true

		listeners = append(listeners, Port53Listener{
			Protocol: proto,
			LocalIP:  ipPart,
			Port:     portNum,
			Process:  procName,
			PID:      pid,
		})
	}

	if len(listeners) == 0 {
		return Port53Check{
			Owner:     "none",
			Conflict:  false,
			Listeners: []Port53Listener{},
		}
	}

	var conflicts []string
	hasPdns := false

	for _, l := range listeners {
		if isPdnsProcess(l.Process) {
			hasPdns = true
			continue
		}
		desc := fmt.Sprintf("%s (%s:%d/%s)", l.Process, l.LocalIP, l.Port, l.Protocol)
		conflicts = append(conflicts, desc)
	}

	if len(conflicts) > 0 {
		owner := listeners[0].Process
		return Port53Check{
			Owner:        owner,
			Conflict:     true,
			ConflictInfo: fmt.Sprintf("port 53 is held by conflicting daemon(s): %s", strings.Join(conflicts, ", ")),
			Listeners:    listeners,
		}
	}

	owner := "none"
	if hasPdns {
		owner = "pdns_server"
	}

	return Port53Check{
		Owner:     owner,
		Conflict:  false,
		Listeners: listeners,
	}
}

func isPdnsProcess(proc string) bool {
	p := strings.ToLower(strings.TrimSpace(proc))
	return p == "pdns_server" || strings.HasPrefix(p, "pdns_server") || p == "pdns"
}

func parseSSLocalAddress(addr string) (string, int, bool) {
	idx := strings.LastIndex(addr, ":")
	if idx <= 0 || idx == len(addr)-1 {
		return "", 0, false
	}

	hostPart := addr[:idx]
	portPart := addr[idx+1:]

	portNum, err := strconv.Atoi(portPart)
	if err != nil {
		return "", 0, false
	}

	// Strip interface suffix like `127.0.0.53%lo` and brackets `[::]`
	if pct := strings.Index(hostPart, "%"); pct != -1 {
		hostPart = hostPart[:pct]
	}
	hostPart = strings.TrimPrefix(hostPart, "[")
	hostPart = strings.TrimSuffix(hostPart, "]")

	return hostPart, portNum, true
}
