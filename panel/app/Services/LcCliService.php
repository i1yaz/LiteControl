<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class LcCliService
{
    protected string $cliPath;
    protected bool $mockMode;

    public function __construct(?string $cliPath = null, ?bool $mockMode = null)
    {
        $this->cliPath = $cliPath ?? env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');
        // Force mock mode if explicit or if we are not running on Linux or CLI binary doesn't exist
        $this->mockMode = $mockMode ?? (
            (bool) env('LC_DEV_MOCK', false) ||
            PHP_OS_FAMILY === 'Windows' ||
            !file_exists($this->cliPath)
        );
    }

    public function isMockMode(): bool
    {
        return $this->mockMode;
    }

    /**
     * Execute an lc-cli command with arguments and return decoded JSON response.
     *
     * @param string $command E.g. 'server', 'user', 'vhost', 'php', 'tuning', 'db', 'security', 'backup', 'module'
     * @param array $args Arguments passed to the command
     * @return array Standardized array: ['status' => 'success|error', 'message' => string, 'data' => mixed]
     */
    public function run(string $command, array $args = []): array
    {
        if ($this->mockMode) {
            return $this->mockResponse($command, $args);
        }

        try {
            $cmd = array_merge(['sudo', $this->cliPath, '--json', $command], $args);
            $result = Process::run($cmd);

            $output = trim($result->output());
            $decoded = json_decode($output, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // Fallback for non-JSON or command execution error
            return [
                'status' => $result->successful() ? 'success' : 'error',
                'message' => $result->successful() ? 'Command executed successfully.' : ($result->errorOutput() ?: 'Command failed with exit code ' . $result->exitCode()),
                'data' => [
                    'raw_output' => $output,
                    'exit_code' => $result->exitCode(),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('LcCliService execution exception: ' . $e->getMessage(), [
                'command' => $command,
                'args' => $args,
            ]);

            return [
                'status' => 'error',
                'message' => 'Exception executing lc-cli: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Provide realistic mock JSON responses for local development and Windows testing.
     */
    protected function mockResponse(string $command, array $args = []): array
    {
        $sub = $args[0] ?? '';

        switch ($command) {
            case 'server':
                if ($sub === 'stats' || empty($sub)) {
                    return [
                        'status' => 'success',
                        'message' => 'Server statistics retrieved',
                        'data' => [
                            'cpu_percent' => 14.5,
                            'cpu_cores' => 4,
                            'load_avg' => [
                                '1m' => 0.42,
                                '5m' => 0.58,
                                '15m' => 0.35,
                            ],
                            'ram_total_mb' => 8192,
                            'ram_used_mb' => 2840,
                            'ram_percent' => 34.6,
                            'swap_total_mb' => 2048,
                            'swap_used_mb' => 128,
                            'disk_total_gb' => 100,
                            'disk_used_gb' => 22.4,
                            'disk_percent' => 22.4,
                            'hostname' => 'panel.local',
                            'server_ip' => '127.0.0.1',
                            'server_time' => now()->format('Y-m-d H:i:s T'),
                            'timezone' => 'UTC',
                            'uptime_human' => '14 days, 3 hours',
                            'uptime_seconds' => 1220400,
                            'os_pretty' => 'Ubuntu 24.04.1 LTS',
                            'ols_version' => 'OpenLiteSpeed 1.8.2',
                            'kernel' => '6.8.0-40-generic',
                        ],
                    ];
                }

                if ($sub === 'services') {
                    return [
                        'status' => 'success',
                        'message' => 'Service status retrieved',
                        'data' => [
                            ['name' => 'openlitespeed', 'service' => 'lshttpd', 'status' => 'active', 'enabled' => true, 'description' => 'OpenLiteSpeed Web Server'],
                            ['name' => 'mariadb', 'service' => 'mariadb', 'status' => 'active', 'enabled' => true, 'description' => 'MariaDB SQL Database Server'],
                            ['name' => 'redis', 'service' => 'redis-server', 'status' => 'active', 'enabled' => true, 'description' => 'Redis In-Memory Cache & Queue'],
                            ['name' => 'ufw', 'service' => 'ufw', 'status' => 'active', 'enabled' => true, 'description' => 'Uncomplicated Firewall'],
                            ['name' => 'cron', 'service' => 'cron', 'status' => 'active', 'enabled' => true, 'description' => 'Cron Scheduled Tasks'],
                            ['name' => 'ssh', 'service' => 'ssh', 'status' => 'active', 'enabled' => true, 'description' => 'OpenSSH Remote Access'],
                        ],
                    ];
                }

                if ($sub === 'processes') {
                    return [
                        'status' => 'success',
                        'message' => 'Process list retrieved',
                        'data' => [
                            ['pid' => 1102, 'user' => 'nobody', 'cpu' => 1.2, 'mem' => 2.4, 'vsz' => '342M', 'rss' => '48M', 'stat' => 'S', 'time' => '00:04:12', 'command' => 'openlitespeed (lshttpd - #01)'],
                            ['pid' => 1150, 'user' => 'litecontrol', 'cpu' => 0.8, 'mem' => 1.8, 'vsz' => '210M', 'rss' => '36M', 'stat' => 'S', 'time' => '00:02:44', 'command' => 'lsphp (litecontrol_php)'],
                            ['pid' => 840, 'user' => 'mysql', 'cpu' => 0.5, 'mem' => 4.2, 'vsz' => '1.2G', 'rss' => '120M', 'stat' => 'Sl', 'time' => '00:15:30', 'command' => 'mariadbd'],
                            ['pid' => 910, 'user' => 'redis', 'cpu' => 0.1, 'mem' => 0.5, 'vsz' => '45M', 'rss' => '12M', 'stat' => 'Ssl', 'time' => '00:01:05', 'command' => 'redis-server 127.0.0.1:6379'],
                        ],
                    ];
                }

                if ($sub === 'timezone') {
                    $mode = $args[1] ?? 'get';
                    if ($mode === 'set') {
                        $tz = $args[2] ?? 'UTC';
                        return [
                            'status' => 'success',
                            'message' => "Timezone updated to '$tz'.",
                            'data' => ['timezone' => $tz],
                        ];
                    }
                    return [
                        'status' => 'success',
                        'message' => 'Current timezone.',
                        'data' => ['timezone' => 'UTC'],
                    ];
                }

                if ($sub === 'hostname') {
                    $mode = $args[1] ?? 'get';
                    if ($mode === 'set') {
                        $h = $args[2] ?? 'panel.local';
                        return [
                            'status' => 'success',
                            'message' => "Hostname updated to '$h'.",
                            'data' => ['hostname' => $h],
                        ];
                    }
                    return [
                        'status' => 'success',
                        'message' => 'Current hostname.',
                        'data' => ['hostname' => 'panel.local'],
                    ];
                }

                if ($sub === 'kill-process') {
                    $pid = (int) ($args[1] ?? 0);
                    return [
                        'status' => 'success',
                        'message' => "Process $pid terminated.",
                        'data' => ['pid' => $pid, 'terminated' => true],
                    ];
                }

                if ($sub === 'service-action') {
                    $svc = $args[1] ?? 'openlitespeed';
                    $act = $args[2] ?? 'restart';
                    return [
                        'status' => 'success',
                        'message' => "Executed '$act' on service '$svc'.",
                        'data' => ['service' => $svc, 'action' => $act, 'status' => 'active'],
                    ];
                }

                if ($sub === 'logs') {
                    $logType = $args[1] ?? 'ols-error';
                    $lines = (int) ($args[2] ?? 100);
                    return [
                        'status' => 'success',
                        'message' => 'Log entries retrieved.',
                        'data' => [
                            'log_type' => $logType,
                            'file' => "/usr/local/lsws/logs/{$logType}.log",
                            'lines' => $lines,
                            'content' => "[2026-11-06 00:00:01] [NOTICE] LiteControl system healthy.\n[2026-11-06 01:15:22] [INFO] Worker pool reloaded cleanly.\n[2026-11-06 03:00:00] [NOTICE] Daily log rotation executed.",
                        ],
                    ];
                }

                if ($sub === 'webadmin-status') {
                    $mockFile = sys_get_temp_dir() . '/mock_enable_webadmin';
                    $enabled = file_exists($mockFile);
                    $ips = $enabled ? trim((string) @file_get_contents($mockFile)) : '';
                    return [
                        'status' => 'success',
                        'message' => 'WebAdmin status retrieved.',
                        'data' => [
                            'enabled' => $enabled,
                            'port' => 7080,
                            'allowed_ips' => $ips,
                            'enable_file' => '/usr/local/lsws/admin/conf/enable_webadmin',
                        ],
                    ];
                }

                if ($sub === 'webadmin-enable') {
                    $ips = $args[1] ?? '';
                    $mockFile = sys_get_temp_dir() . '/mock_enable_webadmin';
                    @file_put_contents($mockFile, $ips);
                    return [
                        'status' => 'success',
                        'message' => 'OpenLiteSpeed WebAdmin console enabled on port 7080.',
                        'data' => [
                            'enabled' => true,
                            'port' => 7080,
                            'allowed_ips' => $ips,
                        ],
                    ];
                }

                if ($sub === 'webadmin-disable') {
                    $mockFile = sys_get_temp_dir() . '/mock_enable_webadmin';
                    @unlink($mockFile);
                    return [
                        'status' => 'success',
                        'message' => 'OpenLiteSpeed WebAdmin console disabled.',
                        'data' => [
                            'enabled' => false,
                            'port' => 7080,
                            'allowed_ips' => '',
                        ],
                    ];
                }

                return ['status' => 'success', 'message' => "Mock server action '$sub' succeeded", 'data' => []];

            case 'user':
                if ($sub === 'list') {
                    return [
                        'status' => 'success',
                        'message' => 'User list retrieved',
                        'data' => [
                            ['username' => 'client1', 'uid' => 1001, 'gid' => 1001, 'home' => '/home/client1', 'disk_usage_bytes' => 104857600, 'disk_usage_human' => '100 MB', 'is_locked' => false],
                            ['username' => 'demouser', 'uid' => 1002, 'gid' => 1002, 'home' => '/home/demouser', 'disk_usage_bytes' => 52428800, 'disk_usage_human' => '50 MB', 'is_locked' => false],
                        ],
                    ];
                }
                return ['status' => 'success', 'message' => "User operation '$sub' completed", 'data' => []];

            case 'vhost':
                if ($sub === 'list') {
                    return [
                        'status' => 'success',
                        'message' => 'Virtual hosts retrieved',
                        'data' => [
                            ['domain' => 'client1.com', 'user' => 'client1', 'doc_root' => '/home/client1/client1.com/public_html', 'php_version' => '83', 'ssl' => true],
                            ['domain' => 'demouser.net', 'user' => 'demouser', 'doc_root' => '/home/demouser/demouser.net/public_html', 'php_version' => '82', 'ssl' => false],
                        ],
                    ];
                }
                if ($sub === 'suspend') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "VirtualHost '{$dom}' suspended.",
                        'data' => ['domain' => $dom, 'status' => 'suspended'],
                    ];
                }
                if ($sub === 'unsuspend') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "VirtualHost '{$dom}' unsuspended.",
                        'data' => ['domain' => $dom, 'status' => 'active'],
                    ];
                }
                if ($sub === 'alias-add') {
                    $dom = $args[1] ?? 'example.com';
                    $alias = $args[2] ?? 'alias.example.com';
                    return [
                        'status' => 'success',
                        'message' => "Domain alias '{$alias}' registered for '{$dom}'.",
                        'data' => ['domain' => $dom, 'alias' => $alias],
                    ];
                }
                if ($sub === 'conf-get') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "Configuration retrieved for '{$dom}'.",
                        'data' => [
                            'domain' => $dom,
                            'conf' => "docRoot                   /home/client1/{$dom}/public_html\nvhDomain                  {$dom}\nvhAliases                 www.{$dom}\nadminEmails               admin@{$dom}\nenableGzip                1\nenableBr                  1\n\ncontext / {\n  location                \$DOC_ROOT/\n  allowBrowse             1\n}\n",
                        ],
                    ];
                }
                if ($sub === 'conf-set') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "VirtualHost configuration for '{$dom}' validated and applied.",
                        'data' => [
                            'domain' => $dom,
                            'status' => 'saved',
                        ],
                    ];
                }
                if ($sub === 'size') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "Size calculated for '{$dom}'.",
                        'data' => ['domain' => $dom, 'size_bytes' => 5242880], // 5 MB
                    ];
                }
                return ['status' => 'success', 'message' => "Virtual host operation '$sub' completed", 'data' => []];

            case 'php':
                if ($sub === 'list') {
                    return [
                        'status' => 'success',
                        'message' => 'PHP versions list retrieved',
                        'data' => [
                            ['version' => '74', 'installed' => false, 'active' => false],
                            ['version' => '80', 'installed' => false, 'active' => false],
                            ['version' => '81', 'installed' => true, 'active' => true, 'path' => '/usr/local/lsws/lsphp81/bin/lsphp'],
                            ['version' => '82', 'installed' => true, 'active' => true, 'path' => '/usr/local/lsws/lsphp82/bin/lsphp'],
                            ['version' => '83', 'installed' => true, 'active' => true, 'is_default' => true, 'path' => '/usr/local/lsws/lsphp83/bin/lsphp'],
                            ['version' => '84', 'installed' => false, 'active' => false],
                            ['version' => '85', 'installed' => false, 'active' => false],
                        ],
                    ];
                }
                if ($sub === 'ini-get') {
                    $ver = $args[1] ?? '83';
                    return [
                        'status' => 'success',
                        'message' => "PHP $ver ini configuration retrieved",
                        'data' => [
                            'version' => $ver,
                            'ini_path' => "/usr/local/lsws/lsphp{$ver}/etc/php/{$ver}/litespeed/php.ini",
                            'directives' => [
                                'memory_limit' => '256M',
                                'upload_max_filesize' => '64M',
                                'post_max_size' => '64M',
                                'max_execution_time' => '300',
                                'max_input_vars' => '3000',
                                'display_errors' => 'Off',
                                'disable_functions' => 'exec,passthru,shell_exec,system,proc_open,popen',
                                'date.timezone' => 'UTC',
                            ],
                        ],
                    ];
                }

                if ($sub === 'ext-list') {
                    $ver = $args[1] ?? '83';
                    return [
                        'status' => 'success',
                        'message' => "PHP $ver extensions list retrieved",
                        'data' => [
                            'installed' => ['bcmath', 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'mysql', 'opcache', 'pdo', 'redis', 'soap', 'xml', 'zip'],
                            'available' => ['memcached', 'mongodb', 'pgsql', 'sqlite3', 'xdebug', 'yaml'],
                        ],
                    ];
                }

                if ($sub === 'modules') {
                    $ver = $args[1] ?? '83';
                    return [
                        'status' => 'success',
                        'message' => "PHP $ver loaded modules",
                        'data' => [
                            'Core', 'date', 'libxml', 'openssl', 'pcre', 'zlib', 'filter', 'hash', 'json', 'pcntl', 'readline',
                            'Reflection', 'SPL', 'standard', 'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'ftp', 'gd',
                            'iconv', 'intl', 'mbstring', 'mysqli', 'PDO', 'pdo_mysql', 'Phar', 'posix', 'redis', 'SimpleXML',
                            'soap', 'sockets', 'sodium', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'Zend OPcache'
                        ],
                    ];
                }

                return ['status' => 'success', 'message' => "PHP operation '$sub' completed", 'data' => []];

            case 'db':
                if ($sub === 'list') {
                    return [
                        'status' => 'success',
                        'message' => 'Databases retrieved',
                        'data' => [
                            ['name' => 'client1_wp', 'size_bytes' => 12582912, 'size_human' => '12.0 MB', 'tables' => 14],
                            ['name' => 'client1_shop', 'size_bytes' => 33554432, 'size_human' => '32.0 MB', 'tables' => 28],
                        ],
                    ];
                }
                return ['status' => 'success', 'message' => "Database operation '$sub' completed", 'data' => ['db' => $args[1] ?? '']];

            case 'backup':
                if ($sub === 'list') {
                    return [
                        'status' => 'success',
                        'message' => 'Backups retrieved',
                        'data' => [
                            ['filename' => 'backup-client1-20261101_030000.tar.gz', 'path' => '/home/client1/backups/backup-client1-20261101_030000.tar.gz', 'size_bytes' => 45000000, 'size_human' => '42.9 MB', 'created_at' => '2026-11-01 03:00:00'],
                        ],
                    ];
                }
                if ($sub === 'create') {
                    $ts = now()->format('Ymd_His');
                    $user = $args[1] ?? 'client1';
                    $fn = "backup-{$user}-{$ts}.tar.gz";
                    return [
                        'status' => 'success',
                        'message' => "Backup archive created: $fn",
                        'data' => ['filename' => $fn, 'path' => "/home/{$user}/backups/{$fn}", 'size_bytes' => 52428800],
                    ];
                }
                return ['status' => 'success', 'message' => "Backup operation '$sub' completed", 'data' => []];

            case 'tuning':
                if ($sub === 'set') {
                    return [
                        'status' => 'success',
                        'message' => 'LiteSpeed tuning parameters updated successfully.',
                        'data' => [],
                    ];
                }
                return [
                    'status' => 'success',
                    'message' => 'Tuning configuration retrieved',
                    'data' => [
                        'maxConnections' => 10000,
                        'maxSSLConnections' => 8000,
                        'connTimeout' => 300,
                        'maxKeepAliveReq' => 1000,
                        'keepAliveTimeout' => 5,
                        'maxReqBodySize' => '500M',
                        'enableGzipCompress' => 1,
                        'gzipCompressLevel' => 6,
                        'quicEnable' => 1,
                    ],
                ];

            case 'module':
                if ($sub === 'install') {
                    $mod = $args[1] ?? '';
                    return [
                        'status' => 'success',
                        'message' => "Module '$mod' installed successfully.",
                        'data' => ['module' => $mod, 'installed' => true],
                    ];
                }
                if ($sub === 'remove') {
                    $mod = $args[1] ?? '';
                    return [
                        'status' => 'success',
                        'message' => "Module '$mod' removed successfully.",
                        'data' => ['module' => $mod, 'installed' => false],
                    ];
                }
                if ($sub === 'status') {
                    $mod = $args[1] ?? '';
                    return [
                        'status' => 'success',
                        'message' => "Module status for '$mod'.",
                        'data' => ['module' => $mod, 'state' => 'active', 'installed' => true],
                    ];
                }
                return [
                    'status' => 'success',
                    'message' => 'Module list retrieved',
                    'data' => [
                        ['name' => 'ftp', 'title' => 'Pure-FTPd', 'installed' => false, 'description' => 'FTP server with MySQL backend'],
                        ['name' => 'dns', 'title' => 'PowerDNS', 'installed' => false, 'description' => 'Authoritative DNS server with MySQL backend'],
                        ['name' => 'mail', 'title' => 'Postfix & Dovecot', 'installed' => false, 'description' => 'Full mail server with DKIM and Webmail support'],
                        ['name' => 'csf', 'title' => 'CSF / LFD', 'installed' => false, 'description' => 'ConfigServer Security & Firewall with Login Failure Daemon'],
                        ['name' => 'modsecurity', 'title' => 'ModSecurity + OWASP CRS', 'installed' => false, 'description' => 'Web Application Firewall for OpenLiteSpeed'],
                        ['name' => 'imunifyav', 'title' => 'ImunifyAV Antivirus', 'installed' => false, 'description' => 'Free malware and virus scanner for hosted files'],
                    ],
                ];

            case 'security':
                if ($sub === 'ufw-status') {
                    return [
                        'status' => 'success',
                        'message' => 'UFW status retrieved',
                        'data' => [
                            'status' => 'active',
                            'active' => true,
                            'rules' => [
                                ['id' => 1, 'number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['id' => 2, 'number' => 2, 'to' => '80/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['id' => 3, 'number' => 3, 'to' => '443/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['id' => 4, 'number' => 4, 'to' => '8090/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                            ],
                        ],
                    ];
                }
                if ($sub === 'ufw-allow') {
                    $port = $args[1] ?? '80';
                    $proto = $args[2] ?? 'tcp';
                    $src = $args[3] ?? 'Anywhere';
                    return [
                        'status' => 'success',
                        'message' => "Firewall rule added: ALLOW $port/$proto.",
                        'data' => ['port' => $port, 'proto' => $proto, 'action' => 'allow', 'source' => $src],
                    ];
                }
                if ($sub === 'ufw-deny') {
                    $port = $args[1] ?? '80';
                    $proto = $args[2] ?? 'tcp';
                    $src = $args[3] ?? 'Anywhere';
                    return [
                        'status' => 'success',
                        'message' => "Firewall rule added: DENY $port/$proto.",
                        'data' => ['port' => $port, 'proto' => $proto, 'action' => 'deny', 'source' => $src],
                    ];
                }
                if ($sub === 'ufw-delete') {
                    $spec = $args[1] ?? '';
                    return [
                        'status' => 'success',
                        'message' => "Firewall rule '$spec' deleted successfully.",
                        'data' => ['deleted' => $spec],
                    ];
                }
                if ($sub === 'panel-port') {
                    $newPort = (int) ($args[1] ?? 8090);
                    return [
                        'status' => 'success',
                        'message' => "Panel port changed successfully from 8090 to $newPort.",
                        'data' => ['old_port' => 8090, 'new_port' => $newPort],
                    ];
                }
                if ($sub === 'ssh-port') {
                    $newPort = (int) ($args[1] ?? 22);
                    return [
                        'status' => 'success',
                        'message' => "SSH port changed successfully from 22 to $newPort.",
                        'data' => ['old_port' => 22, 'new_port' => $newPort],
                    ];
                }
                return ['status' => 'success', 'message' => "Security operation '$sub' completed", 'data' => []];

            case 'ssl':
                if ($sub === 'issue') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "SSL certificate issued for $dom.",
                        'data' => ['domain' => $dom, 'ssl_enabled' => true, 'expires_at' => now()->addDays(90)->toIso8601String()],
                    ];
                }
                if ($sub === 'custom') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "Custom SSL certificate installed for $dom.",
                        'data' => ['domain' => $dom, 'ssl_enabled' => true, 'custom' => true],
                    ];
                }
                if ($sub === 'renew') {
                    $dom = $args[1] ?? 'example.com';
                    return [
                        'status' => 'success',
                        'message' => "SSL certificate renewed for $dom.",
                        'data' => ['domain' => $dom, 'ssl_enabled' => true, 'expires_at' => now()->addDays(90)->toIso8601String()],
                    ];
                }
                if ($sub === 'panel-ssl') {
                    return [
                        'status' => 'success',
                        'message' => 'Panel SSL certificate configured successfully.',
                        'data' => ['panel_ssl' => true],
                    ];
                }
                return ['status' => 'success', 'message' => "SSL operation '$sub' completed", 'data' => []];

            default:
                return [
                    'status' => 'success',
                    'message' => "Mock operation '$command $sub' completed successfully.",
                    'data' => [],
                ];
        }
    }
}

