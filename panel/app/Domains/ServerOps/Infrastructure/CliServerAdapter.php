<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliServerAdapter implements ServerDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function isMockMode(): bool
    {
        return $this->lcCli->isMockMode();
    }

    public function getStats(): array
    {
        $res = $this->lcCli->run('server', ['stats']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function getServices(): array
    {
        $res = $this->lcCli->run('server', ['services']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function executeServiceAction(string $service, string $action): array
    {
        $args = ['service-action', $service, $action];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to {$action} service {$service}.", 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

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

    public function disableWebAdmin(): array
    {
        $args = ['webadmin-disable'];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to disable WebAdmin console.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function getProcesses(): array
    {
        $res = $this->lcCli->run('server', ['processes']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function killProcess(int $pid): array
    {
        $args = ['kill-process', (string) $pid];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to kill process {$pid}.", 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function getTimezone(): string
    {
        $res = $this->lcCli->run('server', ['timezone', 'get']);

        return $res['data']['timezone'] ?? 'UTC';
    }

    public function setTimezone(string $timezone): array
    {
        $args = ['timezone', 'set', $timezone];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to update timezone.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function getHostname(): string
    {
        $res = $this->lcCli->run('server', ['hostname', 'get']);

        return $res['data']['hostname'] ?? 'panel.local';
    }

    public function setHostname(string $hostname): array
    {
        $args = ['hostname', 'set', $hostname];
        $res = $this->lcCli->run('server', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to update hostname.', 'server', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function getLogs(string $type = 'ols-error', int $lines = 100): array
    {
        if (! in_array($type, ['ols-error', 'ols-access', 'panel-error'])) {
            $type = 'ols-error';
        }

        $res = $this->lcCli->run('server', ['logs', $type, (string) $lines]);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }
}
