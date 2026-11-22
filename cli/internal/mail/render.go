package mail

import (
	"fmt"
	"os"
	"path/filepath"
)

// RenderedFile represents a configuration file to be written to disk.
type RenderedFile struct {
	RelPath string
	Content string
	Mode    os.FileMode
}

// RenderAllConfigs renders all Postfix, Dovecot (2.3 or 2.4+), Rspamd-Light,
// and dedicated lc-rspamd-redis configuration files.
func RenderAllConfigs(data TemplateData) ([]RenderedFile, error) {
	if data.RspamdUser == "" {
		data.RspamdUser = "_rspamd"
	}
	if data.RedisBinary == "" {
		data.RedisBinary = "/usr/bin/redis-server"
	}
	if data.FallbackCert == "" {
		data.FallbackCert = "/etc/ssl/certs/ssl-cert-snakeoil.pem"
	}
	if data.FallbackKey == "" {
		data.FallbackKey = "/etc/ssl/private/ssl-cert-snakeoil.key"
	}

	type spec struct {
		relPath string
		name    string
		tpl     string
		mode    os.FileMode
	}

	specs := []spec{
		// Postfix core & master
		{relPath: "etc/postfix/main.cf", name: "postfix-main.cf", tpl: postfixMainCFTpl, mode: 0o644},
		{relPath: "etc/postfix/master.cf", name: "postfix-master.cf", tpl: postfixMasterCFTpl, mode: 0o644},
		// Postfix 0640 SQL maps
		{relPath: "etc/postfix/sql/domains.cf", name: "postfix-sql-domains", tpl: postfixSQLDomainsTpl, mode: 0o640},
		{relPath: "etc/postfix/sql/mailboxes.cf", name: "postfix-sql-mailboxes", tpl: postfixSQLMailboxesTpl, mode: 0o640},
		{relPath: "etc/postfix/sql/mailbox-self.cf", name: "postfix-sql-mailbox-self", tpl: postfixSQLMailboxSelfTpl, mode: 0o640},
		{relPath: "etc/postfix/sql/aliases.cf", name: "postfix-sql-aliases", tpl: postfixSQLAliasesTpl, mode: 0o640},
		{relPath: "etc/postfix/sql/sender-mailboxes.cf", name: "postfix-sql-sender-mailboxes", tpl: postfixSQLSenderMailboxesTpl, mode: 0o640},
		{relPath: "etc/postfix/sql/sender-aliases.cf", name: "postfix-sql-sender-aliases", tpl: postfixSQLSenderAliasesTpl, mode: 0o640},
		// Rspamd-Light
		{relPath: "etc/rspamd/local.d/dkim_signing.conf", name: "rspamd-dkim", tpl: rspamdDkimSigningTpl, mode: 0o644},
		{relPath: "etc/rspamd/local.d/redis.conf", name: "rspamd-redis", tpl: rspamdRedisTpl, mode: 0o644},
		{relPath: "etc/rspamd/local.d/ratelimit.conf", name: "rspamd-ratelimit", tpl: rspamdRatelimitTpl, mode: 0o644},
		{relPath: "etc/rspamd/local.d/worker-proxy.inc", name: "rspamd-worker-proxy", tpl: rspamdWorkerProxyTpl, mode: 0o644},
		{relPath: "etc/rspamd/local.d/worker-normal.inc", name: "rspamd-worker-normal", tpl: rspamdWorkerNormalTpl, mode: 0o644},
		{relPath: "etc/rspamd/local.d/worker-controller.inc", name: "rspamd-worker-controller", tpl: rspamdWorkerControllerTpl, mode: 0o644},
		// Dedicated lc-rspamd-redis
		{relPath: "etc/lc-rspamd-redis/redis.conf", name: "lc-rspamd-redis-conf", tpl: lcRspamdRedisConfTpl, mode: 0o640},
		{relPath: "etc/systemd/system/lc-rspamd-redis.service", name: "lc-rspamd-redis-service", tpl: lcRspamdRedisServiceTpl, mode: 0o644},
	}

	// Versioned Dovecot templates
	switch data.DovecotSeries {
	case Dovecot24:
		specs = append(specs,
			spec{relPath: "etc/dovecot/conf.d/auth-sql.conf.ext", name: "dovecot24-sql", tpl: dovecot24SQLTpl, mode: 0o640},
			spec{relPath: "etc/dovecot/conf.d/99-litecontrol.conf", name: "dovecot24-core", tpl: dovecot24CoreTpl, mode: 0o644},
		)
	default:
		specs = append(specs,
			spec{relPath: "etc/dovecot/dovecot-sql.conf.ext", name: "dovecot23-sql", tpl: dovecot23SQLTpl, mode: 0o640},
			spec{relPath: "etc/dovecot/conf.d/99-litecontrol.conf", name: "dovecot23-core", tpl: dovecot23CoreTpl, mode: 0o644},
		)
	}

	files := make([]RenderedFile, 0, len(specs))
	for _, s := range specs {
		content, err := renderTemplate(s.name, s.tpl, data)
		if err != nil {
			return nil, err
		}
		files = append(files, RenderedFile{
			RelPath: s.relPath,
			Content: content,
			Mode:    s.mode,
		})
	}

	return files, nil
}

// WriteFileAtomic writes content to targetPath via a temp file in the same directory.
func WriteFileAtomic(targetPath string, content []byte, mode os.FileMode) error {
	dir := filepath.Dir(targetPath)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("mkdir %s: %w", dir, err)
	}

	tmp, err := os.CreateTemp(dir, ".lc-mail-tmp-*")
	if err != nil {
		return fmt.Errorf("create temp in %s: %w", dir, err)
	}
	tmpName := tmp.Name()
	cleanup := true
	defer func() {
		if cleanup {
			_ = os.Remove(tmpName)
		}
	}()

	if _, err := tmp.Write(content); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("write temp %s: %w", tmpName, err)
	}
	if err := tmp.Chmod(mode); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("chmod %s: %w", tmpName, err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("close %s: %w", tmpName, err)
	}
	if err := os.Rename(tmpName, targetPath); err != nil {
		return fmt.Errorf("rename %s -> %s: %w", tmpName, targetPath, err)
	}
	cleanup = false
	return nil
}
