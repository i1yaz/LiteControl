<?php

namespace App\Domains\Security\Actions;

use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\ValueObjects\Port;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class UpdatePanelPortAction
{
    public function __construct(
        protected FirewallDriverInterface $firewallDriver
    ) {}

    /**
     * Update panel port via CLI and record audit log.
     */
    public function handle(Port $port, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->firewallDriver->updatePanelPort($port);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'change_panel_port',
            'command' => "lc-cli security panel-port {$port->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['port' => $port->value]),
        ]);

        return $result;
    }
}

