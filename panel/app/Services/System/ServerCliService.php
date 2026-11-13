<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class ServerCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Check if LcCliService is running in mock mode.
     *
     * @return bool
     */
    public function isMockMode(): bool
    {
        return $this->lcCli->isMockMode();
    }

    /**
     * Retrieve system performance & resource stats.
     *
     * @return array
     */
    public function getStats(): array
    {
        $res = $this->lcCli->run('server', ['stats']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Retrieve list of system services and their running status.
     *
     * @return array
     */
    public function getServices(): array
    {
        $res = $this->lcCli->run('server', ['services']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Execute an action (start, stop, restart, reload) on a system service.
     *
     * @param string $service
     * @param string $action
     * @return array
     * @throws LcCliException
     */
    public function executeServiceAction(string $service, string $action): array
    {
        $args = ['service-action', $service, $action];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to {$action} service {$service}.", 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Retrieve OpenLiteSpeed WebAdmin status and configuration.
     *
     * @return array
     */
    public function getWebAdminStatus(): array
    {
        $res = $this->lcCli->run('server', ['webadmin-status']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [
            'enabled' => false,
            'port' => 7080,
            'allowed_ips' => '',
        ];
    }

    /**
     * Enable or update OpenLiteSpeed WebAdmin console.
     *
     * @param string|null $allowedIps
     * @return array
     * @throws LcCliException
     */
    public function enableWebAdmin(?string $allowedIps = null): array
    {
        $ips = trim($allowedIps ?? '');
        $args = array_values(array_filter(['webadmin-enable', $ips]));
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to enable WebAdmin console.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Disable OpenLiteSpeed WebAdmin console.
     *
     * @return array
     * @throws LcCliException
     */
    public function disableWebAdmin(): array
    {
        $args = ['webadmin-disable'];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to disable WebAdmin console.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Retrieve list of running processes.
     *
     * @return array
     */
    public function getProcesses(): array
    {
        $res = $this->lcCli->run('server', ['processes']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Terminate a running process by PID.
     *
     * @param int $pid
     * @return array
     * @throws LcCliException
     */
    public function killProcess(int $pid): array
    {
        $args = ['kill-process', (string) $pid];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to kill process {$pid}.", 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Retrieve current server timezone.
     *
     * @return string
     */
    public function getTimezone(): string
    {
        $res = $this->lcCli->run('server', ['timezone', 'get']);

        return $res['data']['timezone'] ?? 'UTC';
    }

    /**
     * Update server timezone.
     *
     * @param string $timezone
     * @return array
     * @throws LcCliException
     */
    public function setTimezone(string $timezone): array
    {
        $args = ['timezone', 'set', $timezone];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to update timezone.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Retrieve current server hostname.
     *
     * @return string
     */
    public function getHostname(): string
    {
        $res = $this->lcCli->run('server', ['hostname', 'get']);

        return $res['data']['hostname'] ?? 'panel.local';
    }

    /**
     * Update server hostname.
     *
     * @param string $hostname
     * @return array
     * @throws LcCliException
     */
    public function setHostname(string $hostname): array
    {
        $args = ['hostname', 'set', $hostname];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to update hostname.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Retrieve server and panel logs.
     *
     * @param string $type Log type: ols-error, ols-access, or panel-error
     * @param int $lines Number of lines to tail
     * @return array
     */
    public function getLogs(string $type = 'ols-error', int $lines = 100): array
    {
        if (!in_array($type, ['ols-error', 'ols-access', 'panel-error'])) {
            $type = 'ols-error';
        }

        $res = $this->lcCli->run('server', ['logs', $type, (string) $lines]);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }
}

