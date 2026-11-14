<?php

namespace App\Domains\Security\Contracts;

use App\Domains\Security\ValueObjects\Port;
use App\Exceptions\System\LcCliException;

interface FirewallDriverInterface
{
    /**
     * Retrieve UFW status and rules.
     */
    public function getUfwStatus(): array;

    /**
     * Add a UFW firewall rule (allow or deny).
     *
     * @throws LcCliException
     */
    public function addRule(string $action, string $port, string $protocol = 'tcp', ?string $sourceIp = null): array;

    /**
     * Delete a UFW firewall rule by rule spec or rule number.
     *
     * @throws LcCliException
     */
    public function deleteRule(string $rule): array;

    /**
     * Update the LiteControl panel listening port.
     *
     * @throws LcCliException
     */
    public function updatePanelPort(Port $port): array;

    /**
     * Update the SSH service port.
     *
     * @throws LcCliException
     */
    public function updateSshPort(Port $port): array;
}
