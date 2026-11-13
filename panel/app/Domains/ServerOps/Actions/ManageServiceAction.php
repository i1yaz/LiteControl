<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class ManageServiceAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver
    ) {}

    /**
     * Execute a service management action (start, stop, restart, reload).
     *
     * @throws LcCliException
     */
    public function handle(string $service, string $action, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->executeServiceAction($service, $action);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => "service_{$action}",
            'command' => "lc-cli server service-action {$service} {$action}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['service' => $service, 'action' => $action]),
        ]);

        return $result;
    }
}

