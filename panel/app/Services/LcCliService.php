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
                return ['status' => 'success', 'message' => "PHP operation '$sub' completed", 'data' => []];

            case 'tuning':
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
                            'rules' => [
                                ['number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['number' => 2, 'to' => '80/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['number' => 3, 'to' => '443/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                                ['number' => 4, 'to' => '8090/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
                            ],
                        ],
                    ];
                }
                return ['status' => 'success', 'message' => "Security operation '$sub' completed", 'data' => []];

            default:
                return [
                    'status' => 'success',
                    'message' => "Mock operation '$command $sub' completed successfully.",
                    'data' => [],
                ];
        }
    }
}

