package pdns

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type fakeRunner struct {
	handlers map[string]func(args []string) (string, error)
	calls    []string
}

func (f *fakeRunner) Run(_ context.Context, name string, args ...string) (string, error) {
	cmdLine := name + " " + strings.Join(args, " ")
	f.calls = append(f.calls, strings.TrimSpace(cmdLine))
	if f.handlers != nil {
		if fn, ok := f.handlers[name]; ok {
			return fn(args)
		}
	}
	return "", nil
}

func TestParseOSReleaseFamily_DebianAndRHEL(t *testing.T) {
	tests := []struct {
		name    string
		content string
		want    OSFamily
		wantErr bool
	}{
		{
			name: "Ubuntu 24.04",
			content: `NAME="Ubuntu"
ID=ubuntu
ID_LIKE=debian
VERSION_ID="24.04"`,
			want: OSFamilyDebian,
		},
		{
			name: "Debian 12",
			content: `ID=debian
VERSION_ID="12"`,
			want: OSFamilyDebian,
		},
		{
			name: "Rocky Linux 9",
			content: `NAME="Rocky Linux"
ID="rocky"
ID_LIKE="rhel centos fedora"`,
			want: OSFamilyRHEL,
		},
		{
			name: "AlmaLinux 9",
			content: `NAME="AlmaLinux"
ID="almalinux"
ID_LIKE="rhel centos fedora"`,
			want: OSFamilyRHEL,
		},
		{
			name:    "Unknown distro",
			content: `ID=arch`,
			wantErr: true,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, err := ParseOSReleaseFamily(tc.content)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("expected error, got %v", got)
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != tc.want {
				t.Fatalf("expected %s, got %s", tc.want, got)
			}
		})
	}
}

func TestPathsForFamily_MatchesPlanTable(t *testing.T) {
	deb := PathsForFamily(OSFamilyDebian, "")
	if deb.MainConfigPath != "/etc/powerdns/pdns.conf" || deb.DropInPath != "/etc/powerdns/pdns.d/99-litecontrol.conf" || deb.ServiceName != "pdns" {
		t.Fatalf("unexpected Debian paths: %+v", deb)
	}

	rhel := PathsForFamily(OSFamilyRHEL, "")
	if rhel.MainConfigPath != "/etc/pdns/pdns.conf" || rhel.DropInPath != "/etc/pdns/pdns.d/99-litecontrol.conf" || rhel.ServiceName != "pdns" {
		t.Fatalf("unexpected RHEL paths: %+v", rhel)
	}
}

func TestSerializeDropIn_DefaultsAndSecurityValidation(t *testing.T) {
	validKey := strings.Repeat("a1", 32) // 64-char hex

	out, err := SerializeDropIn(ConfigPayload{
		APIKey:        validKey,
		Primary:       true,
		Secondary:     false,
		Autosecondary: false,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	expectedLines := []string{
		"api=yes",
		"api-key=" + validKey,
		"webserver=yes",
		"webserver-address=127.0.0.1",
		"webserver-port=8081",
		"webserver-allow-from=127.0.0.1,::1",
		"primary=yes",
		"secondary=no",
		"autosecondary=no",
	}
	for _, line := range expectedLines {
		if !strings.Contains(out, line+"\n") {
			t.Fatalf("expected output to contain %q, got:\n%s", line, out)
		}
	}

	// Non-loopback bind without password or with 0.0.0.0/0 must fail validation
	_, err = SerializeDropIn(ConfigPayload{
		APIKey:             validKey,
		WebserverAddress:   "192.0.2.10",
		WebserverPort:      8081,
		WebserverAllowFrom: "0.0.0.0/0",
		WebserverPassword:  "secret",
	})
	if err == nil || !strings.Contains(err.Error(), "0.0.0.0/0") {
		t.Fatalf("expected 0.0.0.0/0 rejection on non-loopback bind, got: %v", err)
	}

	_, err = SerializeDropIn(ConfigPayload{
		APIKey:             validKey,
		WebserverAddress:   "192.0.2.10",
		WebserverPort:      8081,
		WebserverAllowFrom: "198.51.100.0/24",
	})
	if err == nil || !strings.Contains(err.Error(), "webserver_password is required") {
		t.Fatalf("expected webserver_password requirement on non-loopback bind, got: %v", err)
	}
}

func TestEnsureIncludeDir_UncommentsOrAppends(t *testing.T) {
	commented := "daemon=yes\n# include-dir=/etc/powerdns/pdns.d\nlaunch=gmysql\n"
	updated, changed := EnsureIncludeDir(commented, "/etc/powerdns/pdns.d")
	if !changed {
		t.Fatal("expected changed=true when uncommenting include-dir")
	}
	if !strings.Contains(updated, "\ninclude-dir=/etc/powerdns/pdns.d\n") {
		t.Fatalf("expected active include-dir line, got:\n%s", updated)
	}

	// Calling again should be idempotent
	updated2, changed2 := EnsureIncludeDir(updated, "/etc/powerdns/pdns.d")
	if changed2 || updated2 != updated {
		t.Fatalf("expected idempotent second call, got changed=%v", changed2)
	}
}

func TestParsePort53Listeners_DetectsSystemdResolvedConflict(t *testing.T) {
	ssConflict := `Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process
udp   UNCONN 0      0      127.0.0.53%lo:53   0.0.0.0:*         users:(("systemd-resolve",pid=512,fd=13))
tcp   LISTEN 0      4096   127.0.0.53%lo:53   0.0.0.0:*         users:(("systemd-resolve",pid=512,fd=14))`

	check := ParsePort53Listeners(ssConflict)
	if !check.Conflict {
		t.Fatalf("expected conflict=true for systemd-resolve on port 53, got %+v", check)
	}
	if check.Owner != "systemd-resolve" {
		t.Fatalf("expected owner systemd-resolve, got %q", check.Owner)
	}

	ssPdns := `Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process
udp   UNCONN 0      0      0.0.0.0:53         0.0.0.0:*         users:(("pdns_server",pid=1290,fd=5))
tcp   LISTEN 0      128    0.0.0.0:53         0.0.0.0:*         users:(("pdns_server",pid=1290,fd=7))`

	checkHealthy := ParsePort53Listeners(ssPdns)
	if checkHealthy.Conflict {
		t.Fatalf("expected conflict=false when pdns_server owns port 53, got %+v", checkHealthy)
	}
	if checkHealthy.Owner != "pdns_server" {
		t.Fatalf("expected owner pdns_server, got %q", checkHealthy.Owner)
	}
}

func TestApplyConfig_RollsBackOnConfigCheckFailureWithoutCorruptingLiveFile(t *testing.T) {
	root := t.TempDir()
	osRelease := filepath.Join(root, "os-release")
	if err := os.WriteFile(osRelease, []byte("ID=ubuntu\nID_LIKE=debian\n"), 0o644); err != nil {
		t.Fatalf("write os-release: %v", err)
	}

	confDir := filepath.Join(root, "etc", "powerdns")
	dropInDir := filepath.Join(confDir, "pdns.d")
	if err := os.MkdirAll(dropInDir, 0o755); err != nil {
		t.Fatalf("mkdir pdns.d: %v", err)
	}

	mainConfPath := filepath.Join(confDir, "pdns.conf")
	initialMain := "daemon=yes\nlaunch=gmysql\n"
	if err := os.WriteFile(mainConfPath, []byte(initialMain), 0o640); err != nil {
		t.Fatalf("write pdns.conf: %v", err)
	}

	dropInPath := filepath.Join(dropInDir, "99-litecontrol.conf")
	priorDropIn := "# prior live config\napi=yes\napi-key=" + strings.Repeat("b2", 32) + "\n"
	if err := os.WriteFile(dropInPath, []byte(priorDropIn), 0o640); err != nil {
		t.Fatalf("write prior drop-in: %v", err)
	}

	runner := &fakeRunner{
		handlers: map[string]func(args []string) (string, error){
			"ss": func(_ []string) (string, error) {
				return "", nil
			},
			"pdns_server": func(_ []string) (string, error) {
				return "Fatal error: Trying to set unknown parameter", errors.New("exit status 1")
			},
		},
	}

	mgr := &Manager{
		RootPrefix:    root,
		OSReleasePath: osRelease,
		Runner:        runner,
		SkipChown:     true,
	}

	// 1. Invalid payload fails fast before even running pdns_server
	_, err := mgr.ApplyConfig(context.Background(), ConfigPayload{
		APIKey: "short-invalid-key",
	})
	if err == nil {
		t.Fatal("expected validation error for invalid api_key")
	}
	gotDropIn, _ := os.ReadFile(dropInPath)
	if string(gotDropIn) != priorDropIn {
		t.Fatalf("expected live drop-in to be untouched on invalid payload, got:\n%s", string(gotDropIn))
	}

	// 2. Valid payload that fails `pdns_server --config-check` must roll back both drop-in and pdns.conf
	_, err = mgr.ApplyConfig(context.Background(), ConfigPayload{
		APIKey:  strings.Repeat("c3", 32),
		Primary: true,
	})
	if err == nil || !strings.Contains(err.Error(), "pdns_server --config-check failed") {
		t.Fatalf("expected config-check failure error, got: %v", err)
	}

	gotDropInAfterRollback, _ := os.ReadFile(dropInPath)
	if string(gotDropInAfterRollback) != priorDropIn {
		t.Fatalf("expected drop-in to be rolled back to prior live content, got:\n%s", string(gotDropInAfterRollback))
	}

	gotMainAfterRollback, _ := os.ReadFile(mainConfPath)
	if string(gotMainAfterRollback) != initialMain {
		t.Fatalf("expected main pdns.conf to be rolled back to initial content, got:\n%s", string(gotMainAfterRollback))
	}
}

func TestApplyConfig_SuccessWritesDropInAndRestartsService(t *testing.T) {
	root := t.TempDir()
	osRelease := filepath.Join(root, "os-release")
	if err := os.WriteFile(osRelease, []byte("ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\n"), 0o644); err != nil {
		t.Fatalf("write os-release: %v", err)
	}

	confDir := filepath.Join(root, "etc", "pdns")
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		t.Fatalf("mkdir /etc/pdns: %v", err)
	}

	mainConfPath := filepath.Join(confDir, "pdns.conf")
	if err := os.WriteFile(mainConfPath, []byte("daemon=yes\n# include-dir=/etc/pdns/pdns.d\n"), 0o640); err != nil {
		t.Fatalf("write pdns.conf: %v", err)
	}

	runner := &fakeRunner{
		handlers: map[string]func(args []string) (string, error){
			"ss": func(_ []string) (string, error) {
				return "", nil
			},
			"pdns_server": func(_ []string) (string, error) {
				return "", nil
			},
			"systemctl": func(args []string) (string, error) {
				if len(args) > 0 && args[0] == "is-active" {
					return "active\n", nil
				}
				return "", nil
			},
		},
	}

	mgr := &Manager{
		RootPrefix:    root,
		OSReleasePath: osRelease,
		Runner:        runner,
		SkipChown:     true,
	}

	key := strings.Repeat("d4", 32)
	res, err := mgr.ApplyConfig(context.Background(), ConfigPayload{
		APIKey:        key,
		Primary:       true,
		Secondary:     true,
		Autosecondary: false,
	})
	if err != nil {
		t.Fatalf("ApplyConfig failed: %v", err)
	}

	if res.DistroFamily != OSFamilyRHEL || !res.Validated || !res.IncludeUpdated || res.ServiceState != "active" {
		t.Fatalf("unexpected ApplyResult: %+v", res)
	}

	dropInBytes, err := os.ReadFile(filepath.Join(confDir, "pdns.d", "99-litecontrol.conf"))
	if err != nil {
		t.Fatalf("read written drop-in: %v", err)
	}
	if !strings.Contains(string(dropInBytes), "api-key="+key) || !strings.Contains(string(dropInBytes), "secondary=yes") {
		t.Fatalf("unexpected drop-in content:\n%s", string(dropInBytes))
	}

	mainBytes, _ := os.ReadFile(mainConfPath)
	if !strings.Contains(string(mainBytes), "\ninclude-dir=/etc/pdns/pdns.d\n") {
		t.Fatalf("expected uncommented include-dir in main pdns.conf, got:\n%s", string(mainBytes))
	}
}
