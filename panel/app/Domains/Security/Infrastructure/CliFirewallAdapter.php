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

        return $this->runSecurity($cliArgs, 'Failed to apply firewall rule.');
    }

    public function deleteRule(string $rule): array
    {
        return $this->runSecurity(['ufw-delete', $rule], 'Failed to delete firewall rule.');
    }

    public function updatePanelPort(Port $port): array
    {
        return $this->runSecurity(['panel-port', (string) $port->value], 'Failed to change panel port.');
    }

    public function updateSshPort(Port $port): array
    {
        return $this->runSecurity(['ssh-port', (string) $port->value], 'Failed to change SSH port.');
    }

    /**
     * @throws LcCliException
     */
    protected function runSecurity(array $args, string $defaultError): array
    {
        $res = $this->lcCli->run('security', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? $defaultError, 'security', $args, $res);
        }

        return $res['data'] ?? [];
    }
}
