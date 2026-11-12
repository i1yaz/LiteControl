<?php

namespace App\Actions\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\FirewallCliService;

class UpdatePanelPortAction
{
    public function __construct(
        protected FirewallCliService $firewallCli
    ) {}

    /**
     * Update panel port via CLI and record audit log.
     *
     * @param int $port
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     */
    public function handle(int $port, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->firewallCli->updatePanelPort($port);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'change_panel_port',
            'command' => "lc-cli security panel-port {$port}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['port' => $port]),
        ]);

        return $result;
    }
}

