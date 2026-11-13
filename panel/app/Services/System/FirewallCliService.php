<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class FirewallCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Retrieve UFW status and rules.
     *
     * @return array
     */
    public function getUfwStatus(): array
    {
        $res = $this->lcCli->run('security', ['ufw-status']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return ['active' => false, 'rules' => []];
    }

    /**
     * Add a UFW firewall rule (allow or deny).
     *
     * @param string $action 'allow' or 'deny'
     * @param string $port
     * @param string $protocol 'tcp', 'udp', etc.
     * @param string|null $sourceIp
     * @return array
     * @throws LcCliException
     */
    public function addRule(string $action, string $port, string $protocol = 'tcp', ?string $sourceIp = null): array
    {
        $sub = $action === 'allow' ? 'ufw-allow' : 'ufw-deny';
        $proto = $protocol === 'any' ? 'tcp' : $protocol;
        $src = $sourceIp ?: '';

        $cliArgs = [$sub, $port, $proto];
        if (!empty($src)) {
            $cliArgs[] = $src;
        }

        $res = $this->lcCli->run('security', $cliArgs);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to apply firewall rule.', 'security', $cliArgs);
        }

        return $res['data'] ?? [];
    }

    /**
     * Delete a UFW firewall rule by rule spec or rule number.
     *
     * @param string $rule
     * @return array
     * @throws LcCliException
     */
    public function deleteRule(string $rule): array
    {
        $res = $this->lcCli->run('security', ['ufw-delete', (string) $rule]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to delete firewall rule.', 'security', ['ufw-delete', (string) $rule]);
        }

        return $res['data'] ?? [];
    }

    /**
     * Update the LiteControl panel listening port.
     *
     * @param int $port
     * @return array
     * @throws LcCliException
     */
    public function updatePanelPort(int $port): array
    {
        $res = $this->lcCli->run('security', ['panel-port', (string) $port]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to change panel port.', 'security', ['panel-port', (string) $port]);
        }

        return $res['data'] ?? [];
    }

    /**
     * Update the SSH service port.
     *
     * @param int $port
     * @return array
     * @throws LcCliException
     */
    public function updateSshPort(int $port): array
    {
        $res = $this->lcCli->run('security', ['ssh-port', (string) $port]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to change SSH port.', 'security', ['ssh-port', (string) $port]);
        }

        return $res['data'] ?? [];
    }
}

