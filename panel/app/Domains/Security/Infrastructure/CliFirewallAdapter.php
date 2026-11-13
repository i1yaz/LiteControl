<?php

namespace App\Domains\Security\Infrastructure;

use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\ValueObjects\Port;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliFirewallAdapter implements FirewallDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function getUfwStatus(): array
    {
        $res = $this->lcCli->run('security', ['ufw-status']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return ['active' => false, 'rules' => []];
    }

    public function addRule(string $action, string $port, string $protocol = 'tcp', ?string $sourceIp = null): array
    {
        $sub = $action === 'allow' ? 'ufw-allow' : 'ufw-deny';
        $proto = $protocol === 'any' ? 'tcp' : $protocol;
        $src = $sourceIp ?: '';

        $cliArgs = [$sub, $port, $proto];
        if (! empty($src)) {
            $cliArgs[] = $src;
        }

        $res = $this->lcCli->run('security', $cliArgs);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to apply firewall rule.', 'security', $cliArgs);
        }

        return $res['data'] ?? [];
    }

    public function deleteRule(string $rule): array
    {
        $res = $this->lcCli->run('security', ['ufw-delete', $rule]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to delete firewall rule.', 'security', ['ufw-delete', $rule]);
        }

        return $res['data'] ?? [];
    }

    public function updatePanelPort(Port $port): array
    {
        $res = $this->lcCli->run('security', ['panel-port', (string) $port->value]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to change panel port.', 'security', ['panel-port', (string) $port->value]);
        }

        return $res['data'] ?? [];
    }

    public function updateSshPort(Port $port): array
    {
        $res = $this->lcCli->run('security', ['ssh-port', (string) $port->value]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to change SSH port.', 'security', ['ssh-port', (string) $port->value]);
        }

        return $res['data'] ?? [];
    }
}

