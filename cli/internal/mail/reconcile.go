package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// ReconcileOptions configures `lc-mail reconcile`.
type ReconcileOptions struct {
	Scope               string        // all (default), sni, dkim, filter, srs
	DBHost              string
	DBName              string
	DBUser              string
	DBPassword          string
	CertStoreDir        string
	RootDir             string        // Defaults to "/" on Linux; overridable in tests.
	DovecotSeries       DovecotSeries // Optional; probed via doveconf/dovecot if empty.
	RetiredKeyRetention time.Duration // Defaults to 7 * 24h.
	Now                 func() time.Time
	Runner              CommandRunner
	StateReader         StateReader
}

// ReconcileResult is emitted as JSON to stdout by `lc-mail reconcile`.
type ReconcileResult struct {
	Status    string             `json:"status"`
	Scope     string             `json:"scope"`
	Reloaded  []string           `json:"reloaded"`
	Unchanged []string           `json:"unchanged"`
	Warnings  []ReconcileWarning `json:"warnings"`
}

type stagedOp struct {
	path      string
	content   []byte
	mode      os.FileMode
	remove    bool
	daemon    string
	changed   bool
	existed   bool
	oldBytes  []byte
	oldMode   os.FileMode
}

// Reconcile is the authoritative idempotent state builder for the LiteControl mail stack.
func Reconcile(ctx context.Context, opts ReconcileOptions) (ReconcileResult, error) {
	scope := strings.ToLower(strings.TrimSpace(opts.Scope))
	if scope == "" {
		scope = "all"
	}
	switch scope {
	case "all", "sni", "dkim", "filter", "srs":
	default:
		return ReconcileResult{}, fmt.Errorf("invalid reconcile scope %q (allowed: all, sni, dkim, filter, srs)", opts.Scope)
	}

	runner := opts.Runner
	if runner == nil {
		runner = ExecRunner{}
	}

	rootDir := opts.RootDir
	if rootDir == "" {
		rootDir = string(os.PathSeparator)
	}

	nowFn := opts.Now
	if nowFn == nil {
		nowFn = time.Now
	}

	retention := opts.RetiredKeyRetention
	if retention <= 0 {
		retention = 7 * 24 * time.Hour
	}

	// 1. Acquire exclusive flock on /run/lc-mail-reconcile.lock.
	lockPath := filepath.Join(rootDir, "run", "lc-mail-reconcile.lock")
	lockFile, err := acquireReconcileLock(lockPath)
	if err != nil {
		return ReconcileResult{}, err
	}
	defer releaseReconcileLock(lockFile)

	// 2. Read authoritative state from MariaDB & cert store.
	reader := opts.StateReader
	if reader == nil {
		reader = MariaDBStateReader{
			DBHost:       opts.DBHost,
			DBName:       opts.DBName,
			DBUser:       opts.DBUser,
			DBPassword:   opts.DBPassword,
			CertStoreDir: opts.CertStoreDir,
			RootDir:      rootDir,
			Runner:       runner,
		}
	}

	state, err := reader.ReadState(ctx)
	if err != nil {
		return ReconcileResult{}, fmt.Errorf("read mail state: %w", err)
	}

	dovecotSeries := opts.DovecotSeries
	if dovecotSeries == "" && (scope == "all" || scope == "sni") {
		if verOut, err := runner.Run(ctx, "dovecot", "--version"); err == nil {
			if _, series, err := DetectDovecotSeries(verOut); err == nil {
				dovecotSeries = series
			}
		}
		if dovecotSeries == "" {
			dovecotSeries = Dovecot23
		}
	}

	var ops []*stagedOp
	warnings := make([]ReconcileWarning, 0)
	var tempArtifacts []string
	defer func() {
		for _, f := range tempArtifacts {
			_ = os.Remove(f)
		}
	}()

	stageWrite := func(relPath string, content []byte, mode os.FileMode, daemon string) {
		absPath := filepath.Join(rootDir, filepath.FromSlash(relPath))
		op := &stagedOp{
			path:    absPath,
			content: content,
			mode:    mode,
			daemon:  daemon,
		}
		if existing, err := os.ReadFile(absPath); err == nil {
			op.existed = true
			op.oldBytes = existing
			if info, statErr := os.Stat(absPath); statErr == nil {
				op.oldMode = info.Mode()
			}
			op.changed = BytesSHA256(existing) != BytesSHA256(content)
		} else {
			op.changed = true
		}
		ops = append(ops, op)
	}

	stageRemove := func(absPath string, daemon string) {
		existing, err := os.ReadFile(absPath)
		if err != nil {
			return
		}
		var oldMode os.FileMode = 0o644
		if info, statErr := os.Stat(absPath); statErr == nil {
			oldMode = info.Mode()
		}
		ops = append(ops, &stagedOp{
			path:     absPath,
			remove:   true,
			daemon:   daemon,
			changed:  true,
			existed:  true,
			oldBytes: existing,
			oldMode:  oldMode,
		})
	}

	// 3. Stage configs atomically per scope.
	if scope == "all" || scope == "sni" {
		sniMapContent := renderPostfixSniMap(state)
		sniMapRel := "etc/postfix/vmail_ssl.map"
		sniMapAbs := filepath.Join(rootDir, filepath.FromSlash(sniMapRel))
		existingMap, mapErr := os.ReadFile(sniMapAbs)
		mapChanged := mapErr != nil || BytesSHA256(existingMap) != BytesSHA256([]byte(sniMapContent))

		if mapChanged {
			// Compile postmap -F into a temp file BEFORE touching live config.
			tmpDir := filepath.Dir(sniMapAbs)
			if err := os.MkdirAll(tmpDir, 0o755); err != nil {
				return ReconcileResult{}, fmt.Errorf("mkdir postfix dir: %w", err)
			}
			tmpMap, err := os.CreateTemp(tmpDir, ".lc-vmail-ssl-*.map")
			if err != nil {
				return ReconcileResult{}, fmt.Errorf("create temp sni map: %w", err)
			}
			tmpMapPath := tmpMap.Name()
			tempArtifacts = append(tempArtifacts, tmpMapPath, tmpMapPath+".db")
			if _, err := tmpMap.WriteString(sniMapContent); err != nil {
				_ = tmpMap.Close()
				return ReconcileResult{}, fmt.Errorf("write temp sni map: %w", err)
			}
			_ = tmpMap.Close()

			if _, err := runner.Run(ctx, "postmap", "-F", tmpMapPath); err != nil {
				return ReconcileResult{}, fmt.Errorf("postmap -F validation failed: %w", err)
			}

			stageWrite(sniMapRel, []byte(sniMapContent), 0o644, "postfix")
			if dbBytes, err := os.ReadFile(tmpMapPath + ".db"); err == nil {
				stageWrite(sniMapRel+".db", dbBytes, 0o644, "postfix")
			}
		}

		// Render per-domain Dovecot SNI files & prune stale ones.
		sniDir := filepath.Join(rootDir, "etc", "dovecot", "conf.d", "sni")
		desiredSniFiles := make(map[string]bool)
		for _, dom := range state.Domains {
			if !dom.Active {
				continue
			}
			pair, ok := state.Certs[dom.Domain]
			if !ok {
				continue
			}
			fileName := dom.Domain + ".conf"
			desiredSniFiles[fileName] = true
			confContent := renderDovecotSniConf(dom.Domain, pair, dovecotSeries)
			stageWrite("etc/dovecot/conf.d/sni/"+fileName, []byte(confContent), 0o644, "dovecot")
		}
		if entries, err := os.ReadDir(sniDir); err == nil {
			for _, e := range entries {
				if e.IsDir() || !strings.HasSuffix(e.Name(), ".conf") {
					continue
				}
				if !desiredSniFiles[e.Name()] {
					stageRemove(filepath.Join(sniDir, e.Name()), "dovecot")
				}
			}
		}
	}

	if scope == "all" || scope == "dkim" {
		dkimMapContent, dkimWarnings := renderDkimSelectorMap(rootDir, state)
		warnings = append(warnings, dkimWarnings...)
		stageWrite("etc/rspamd/local.d/lc_dkim_selectors.map", []byte(dkimMapContent), 0o644, "rspamd")

		// Prune orphaned retired keys past retention.
		activeKeyFiles := make(map[string]bool)
		for _, k := range state.DkimKeys {
			if k.Status == "active" || k.Status == "rotating" || k.Status == "pending" {
				activeKeyFiles[fmt.Sprintf("%s.%s.key", k.Domain, k.Selector)] = true
			}
		}
		dkimDir := filepath.Join(rootDir, "var", "lib", "rspamd", "dkim")
		if entries, err := os.ReadDir(dkimDir); err == nil {
			cutoff := nowFn().Add(-retention)
			for _, e := range entries {
				if e.IsDir() || !strings.HasSuffix(e.Name(), ".key") {
					continue
				}
				if activeKeyFiles[e.Name()] {
					continue
				}
				if info, infoErr := e.Info(); infoErr == nil && info.ModTime().Before(cutoff) {
					stageRemove(filepath.Join(dkimDir, e.Name()), "rspamd")
				}
			}
		}
	}

	if scope == "all" || scope == "filter" {
		settingsContent := renderRspamdSettings(state)
		stageWrite("etc/rspamd/local.d/settings.conf", []byte(settingsContent), 0o644, "rspamd")

		ratelimitContent := renderRspamdRatelimitMap(state)
		stageWrite("etc/rspamd/local.d/lc_ratelimits.map", []byte(ratelimitContent), 0o644, "rspamd")

		// Atomic Postfix smtpd_milters / non_smtpd_milters update if main.cf exists.
		mainCfAbs := filepath.Join(rootDir, "etc", "postfix", "main.cf")
		if mainCfBytes, err := os.ReadFile(mainCfAbs); err == nil {
			smtpdMilters := state.PostfixMilt
			if smtpdMilters == "" && state.FilterMode == "full" {
				smtpdMilters = "inet:127.0.0.1:11332"
			}
			updated := updatePostfixMilters(string(mainCfBytes), smtpdMilters, "inet:127.0.0.1:11332")
			stageWrite("etc/postfix/main.cf", []byte(updated), 0o644, "postfix")
		}
	}

	if scope == "all" || scope == "srs" {
		srsRel := detectPostsrsdConfigPath(rootDir)
		srsAbs := filepath.Join(rootDir, filepath.FromSlash(srsRel))
		existingSRS, _ := os.ReadFile(srsAbs)
		updatedSRS := updatePostsrsdExcludeDomains(string(existingSRS), state.Domains)
		stageWrite(srsRel, []byte(updatedSRS), 0o644, "postsrsd")
	}

	// Determine which daemons actually have changes.
	changedDaemons := make(map[string]bool)
	var applied []*stagedOp
	for _, op := range ops {
		if !op.changed {
			continue
		}
		changedDaemons[op.daemon] = true
		if op.remove {
			if err := os.Remove(op.path); err != nil && !os.IsNotExist(err) {
				rollbackStagedOps(applied)
				return ReconcileResult{}, fmt.Errorf("remove %s: %w", op.path, err)
			}
		} else {
			if err := WriteFileAtomic(op.path, op.content, op.mode); err != nil {
				rollbackStagedOps(applied)
				return ReconcileResult{}, fmt.Errorf("stage %s: %w", op.path, err)
			}
		}
		applied = append(applied, op)
	}

	// 4. Pre-reload validation gates (only if any config changed).
	if len(applied) > 0 {
		managedPaths := []string{
			"/etc/postfix",
			"/etc/dovecot",
			"/etc/rspamd",
			"/var/lib/rspamd/dkim",
		}
		if err := RestoreSELinuxContexts(ctx, rootDir, runner, managedPaths); err != nil {
			rollbackStagedOps(applied)
			return ReconcileResult{}, fmt.Errorf("selinux restorecon failed (rolled back): %w", err)
		}
		if _, err := runner.Run(ctx, "postfix", "check"); err != nil {
			rollbackStagedOps(applied)
			return ReconcileResult{}, fmt.Errorf("postfix check failed (rolled back): %w", err)
		}
		if _, err := runner.Run(ctx, "doveconf", "-n"); err != nil {
			rollbackStagedOps(applied)
			return ReconcileResult{}, fmt.Errorf("doveconf -n failed (rolled back): %w", err)
		}
		if _, err := runner.Run(ctx, "rspamadm", "configtest"); err != nil {
			rollbackStagedOps(applied)
			return ReconcileResult{}, fmt.Errorf("rspamadm configtest failed (rolled back): %w", err)
		}
	}

	// 5. Reload only daemons whose staged config checksum changed.
	allDaemons := []string{"postfix", "dovecot", "rspamd", "postsrsd"}
	reloaded := make([]string, 0)
	unchanged := make([]string, 0)
	for _, svc := range allDaemons {
		if changedDaemons[svc] {
			action := "reload-or-restart"
			if svc == "postsrsd" {
				action = "restart"
			}
			if _, err := runner.Run(ctx, "systemctl", action, svc); err != nil {
				return ReconcileResult{}, fmt.Errorf("reload %s: %w", svc, err)
			}
			reloaded = append(reloaded, svc)
		} else {
			unchanged = append(unchanged, svc)
		}
	}

	return ReconcileResult{
		Status:    "success",
		Scope:     scope,
		Reloaded:  reloaded,
		Unchanged: unchanged,
		Warnings:  warnings,
	}, nil
}

func rollbackStagedOps(applied []*stagedOp) {
	for i := len(applied) - 1; i >= 0; i-- {
		op := applied[i]
		if op.existed {
			mode := op.oldMode
			if mode == 0 {
				mode = 0o644
			}
			_ = WriteFileAtomic(op.path, op.oldBytes, mode)
		} else {
			_ = os.Remove(op.path)
		}
	}
}

func renderPostfixSniMap(state ReconcileState) string {
	var b strings.Builder
	b.WriteString("# Managed by LiteControl lc-mail reconcile\n")
	domains := make([]string, 0, len(state.Domains))
	for _, d := range state.Domains {
		if d.Active {
			if _, ok := state.Certs[d.Domain]; ok {
				domains = append(domains, d.Domain)
			}
		}
	}
	sort.Strings(domains)
	for _, d := range domains {
		pair := state.Certs[d]
		fmt.Fprintf(&b, "mail.%s %s %s\n", d, pair.KeyPath, pair.CertPath)
		fmt.Fprintf(&b, "%s %s %s\n", d, pair.KeyPath, pair.CertPath)
	}
	return b.String()
}

func renderDovecotSniConf(domain string, pair CertPair, series DovecotSeries) string {
	var b strings.Builder
	b.WriteString("# Managed by LiteControl lc-mail reconcile\n")
	fmt.Fprintf(&b, "local_name mail.%s %s {\n", domain, domain)
	if series == Dovecot24 {
		fmt.Fprintf(&b, "  ssl_server_cert_file = %s\n", pair.CertPath)
		fmt.Fprintf(&b, "  ssl_server_key_file = %s\n", pair.KeyPath)
	} else {
		fmt.Fprintf(&b, "  ssl_cert = <%s\n", pair.CertPath)
		fmt.Fprintf(&b, "  ssl_key = <%s\n", pair.KeyPath)
	}
	b.WriteString("}\n")
	return b.String()
}

func renderDkimSelectorMap(rootDir string, state ReconcileState) (string, []ReconcileWarning) {
	var b strings.Builder
	b.WriteString("# Managed by LiteControl lc-mail reconcile\n")
	warnings := make([]ReconcileWarning, 0)

	keys := make([]DkimKeyState, 0, len(state.DkimKeys))
	for _, k := range state.DkimKeys {
		if k.Status == "active" {
			keys = append(keys, k)
		}
	}
	sort.Slice(keys, func(i, j int) bool {
		if keys[i].Domain == keys[j].Domain {
			return keys[i].Selector < keys[j].Selector
		}
		return keys[i].Domain < keys[j].Domain
	})

	for _, k := range keys {
		keyPath := k.PrivateKeyPath
		if keyPath == "" {
			keyPath = fmt.Sprintf("/var/lib/rspamd/dkim/%s.%s.key", k.Domain, k.Selector)
		}
		checkPath := keyPath
		if rootDir != string(os.PathSeparator) && strings.HasPrefix(keyPath, "/") {
			checkPath = filepath.Join(rootDir, filepath.FromSlash(strings.TrimPrefix(keyPath, "/")))
		}

		actualHash, err := FileSHA256(checkPath)
		if err != nil {
			warnings = append(warnings, ReconcileWarning{
				Code:     "dkim_key_missing",
				Domain:   k.Domain,
				Selector: k.Selector,
				Message:  fmt.Sprintf("DKIM private key missing or unreadable at %s: %v", keyPath, err),
			})
			continue
		}
		if k.KeySHA256 != "" && !strings.EqualFold(actualHash, k.KeySHA256) {
			warnings = append(warnings, ReconcileWarning{
				Code:     "dkim_key_sha256_drift",
				Domain:   k.Domain,
				Selector: k.Selector,
				Message:  fmt.Sprintf("DKIM private key SHA-256 mismatch for %s (%s): expected %s, got %s", k.Domain, k.Selector, k.KeySHA256, actualHash),
			})
			continue
		}
		fmt.Fprintf(&b, "%s %s\n", k.Domain, k.Selector)
	}

	return b.String(), warnings
}

func renderRspamdSettings(state ReconcileState) string {
	var b strings.Builder
	b.WriteString("# Managed by LiteControl lc-mail reconcile\n")
	domains := make([]MailDomainState, 0, len(state.Domains))
	for _, d := range state.Domains {
		if d.Active {
			domains = append(domains, d)
		}
	}
	sort.Slice(domains, func(i, j int) bool { return domains[i].Domain < domains[j].Domain })

	for _, d := range domains {
		if !d.SpamFilterEnabled {
			continue
		}
		hdr := d.SpamHeaderScore
		if hdr <= 0 {
			hdr = 6.0
		}
		rej := d.SpamRejectScore
		if rej <= 0 {
			rej = 15.0
		}
		safeName := strings.NewReplacer(".", "_", "-", "_").Replace(d.Domain)
		fmt.Fprintf(&b, "lc_domain_%s {\n", safeName)
		fmt.Fprintf(&b, "  rcpt = \"@%s\";\n", d.Domain)
		b.WriteString("  apply {\n")
		b.WriteString("    actions {\n")
		fmt.Fprintf(&b, "      add_header = %.2f;\n", hdr)
		fmt.Fprintf(&b, "      reject = %.2f;\n", rej)
		b.WriteString("    }\n")
		b.WriteString("  }\n")
		b.WriteString("}\n")
	}
	return b.String()
}

func renderRspamdRatelimitMap(state ReconcileState) string {
	var b strings.Builder
	b.WriteString("# Managed by LiteControl lc-mail reconcile\n")
	domains := make([]MailDomainState, 0, len(state.Domains))
	for _, d := range state.Domains {
		if d.Active && d.OutboundHourlyLimit > 0 {
			domains = append(domains, d)
		}
	}
	sort.Slice(domains, func(i, j int) bool { return domains[i].Domain < domains[j].Domain })
	for _, d := range domains {
		fmt.Fprintf(&b, "%s %d / 1h\n", d.Domain, d.OutboundHourlyLimit)
	}
	return b.String()
}

func updatePostfixMilters(mainCf, smtpdMilters, nonSmtpdMilters string) string {
	lines := strings.Split(mainCf, "\n")
	foundSmtpd := false
	foundNonSmtpd := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "smtpd_milters") && strings.Contains(trimmed, "=") {
			if smtpdMilters == "" {
				lines[i] = "smtpd_milters ="
			} else {
				lines[i] = "smtpd_milters = " + smtpdMilters
			}
			foundSmtpd = true
		} else if strings.HasPrefix(trimmed, "non_smtpd_milters") && strings.Contains(trimmed, "=") {
			lines[i] = "non_smtpd_milters = " + nonSmtpdMilters
			foundNonSmtpd = true
		}
	}
	if !foundSmtpd {
		if smtpdMilters == "" {
			lines = append(lines, "smtpd_milters =")
		} else {
			lines = append(lines, "smtpd_milters = "+smtpdMilters)
		}
	}
	if !foundNonSmtpd {
		lines = append(lines, "non_smtpd_milters = "+nonSmtpdMilters)
	}
	return strings.Join(lines, "\n")
}

func detectPostsrsdConfigPath(rootDir string) string {
	if _, err := os.Stat(filepath.Join(rootDir, "etc", "sysconfig", "postsrsd")); err == nil {
		return "etc/sysconfig/postsrsd"
	}
	if info, err := DetectOS(rootDir); err == nil && info.Family == OSFamilyRHEL {
		return "etc/sysconfig/postsrsd"
	}
	return "etc/default/postsrsd"
}

func updatePostsrsdExcludeDomains(existing string, domains []MailDomainState) string {
	active := make([]string, 0, len(domains))
	for _, d := range domains {
		if d.Active {
			active = append(active, d.Domain)
		}
	}
	sort.Strings(active)
	targetLine := fmt.Sprintf("SRS_EXCLUDE_DOMAINS=%s", strings.Join(active, ","))

	if strings.TrimSpace(existing) == "" {
		return "# Managed by LiteControl lc-mail reconcile\n" + targetLine + "\n"
	}

	lines := strings.Split(strings.TrimRight(existing, "\n"), "\n")
	replaced := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "SRS_EXCLUDE_DOMAINS=") || strings.HasPrefix(trimmed, "#SRS_EXCLUDE_DOMAINS=") {
			lines[i] = targetLine
			replaced = true
			break
		}
	}
	if !replaced {
		lines = append(lines, targetLine)
	}
	return strings.Join(lines, "\n") + "\n"
}
