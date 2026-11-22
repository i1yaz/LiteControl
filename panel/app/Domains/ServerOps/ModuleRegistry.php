<?php

declare(strict_types=1);

namespace App\Domains\ServerOps;

final class ModuleRegistry
{
    /**
     * Authoritative catalog of optional modules with parent/dependency links.
     *
     * @return array<string, array{
     *     name: string,
     *     title: string,
     *     driver: string,
     *     parent: ?string,
     *     depends_on: list<string>,
     *     description: string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'ftp' => [
                'name' => 'ftp',
                'title' => 'Pure-FTPd',
                'driver' => 'pure-ftpd',
                'parent' => null,
                'depends_on' => [],
                'description' => 'FTP server with MariaDB backend',
            ],
            'dns' => [
                'name' => 'dns',
                'title' => 'DNS Server (PowerDNS)',
                'driver' => 'powerdns',
                'parent' => null,
                'depends_on' => [],
                'description' => 'Authoritative DNS server with MariaDB backend',
            ],
            'mail_core' => [
                'name' => 'mail_core',
                'title' => 'Mail Server Core (Postfix & Dovecot)',
                'driver' => 'postfix_dovecot',
                'parent' => null,
                'depends_on' => [],
                'description' => 'Core mail stack: Postfix, Dovecot, Rspamd-Light (DKIM & outbound ratelimit), and postsrsd',
            ],
            'mail_filter' => [
                'name' => 'mail_filter',
                'title' => 'Mail Anti-Spam Filter (Rspamd)',
                'driver' => 'rspamd',
                'parent' => 'mail_core',
                'depends_on' => ['mail_core'],
                'description' => 'Full inbound anti-spam filtering and Sieve Junk auto-filing via Rspamd',
            ],
            'clamav' => [
                'name' => 'clamav',
                'title' => 'ClamAV Antivirus',
                'driver' => 'clamav',
                'parent' => 'mail_filter',
                'depends_on' => ['mail_core', 'mail_filter'],
                'description' => 'Optional ClamAV virus scanner for inbound mail (requires >= 4 GB RAM)',
            ],
            'mail_webmail' => [
                'name' => 'mail_webmail',
                'title' => 'Webmail (SnappyMail)',
                'driver' => 'snappymail',
                'parent' => 'mail_core',
                'depends_on' => ['mail_core'],
                'description' => 'Isolated SnappyMail webmail client served via OpenLiteSpeed',
            ],
            'modsecurity' => [
                'name' => 'modsecurity',
                'title' => 'ModSecurity + OWASP CRS',
                'driver' => 'modsecurity',
                'parent' => null,
                'depends_on' => [],
                'description' => 'Web Application Firewall for OpenLiteSpeed',
            ],
            'imunifyav' => [
                'name' => 'imunifyav',
                'title' => 'ImunifyAV Antivirus',
                'driver' => 'imunifyav',
                'parent' => null,
                'depends_on' => [],
                'description' => 'Free malware and virus scanner for hosted files',
            ],
        ];
    }

    /**
     * All module keys permitted by the /modules endpoints (including legacy aliases).
     *
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::definitions()),
            ['powerdns', 'mail']
        )));
    }

    /**
     * Merge CLI-reported module status with registry metadata (parent, depends_on, driver).
     *
     * @param  array<int, array<string, mixed>>  $cliModules
     * @return array<int, array<string, mixed>>
     */
    public static function mergeWithCliList(array $cliModules): array
    {
        $byName = [];
        foreach ($cliModules as $item) {
            if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                $name = $item['name'] === 'powerdns' ? 'dns' : $item['name'];
                $byName[$name] = array_merge($item, ['name' => $name]);
            }
        }

        $merged = [];
        foreach (self::definitions() as $key => $def) {
            $cli = $byName[$key] ?? [];
            $merged[] = array_merge($def, [
                'installed' => (bool) ($cli['installed'] ?? false),
            ], $cli);
        }

        return $merged;
    }
}
