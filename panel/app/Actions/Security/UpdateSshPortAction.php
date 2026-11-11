<?php

namespace App\Actions\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\FirewallCliService;

class UpdateSshPortAction
{
    public function __construct(
        protected FirewallCliService $firewallCli
    ) {}

    /**
     * Update SSH service port via CLI and record audit log.
     *
     * @param int $port
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     */
    public function handle(int $port, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->firewallCli->updateSshPort($port);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'change_ssh_port',
            'command' => "lc-cli security ssh-port {$port}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['port' => $port]),
        ]);

        return $result;
    }
}

