<?php

namespace App\Actions\Server;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class ManageServiceAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Execute a service management action (start, stop, restart, reload).
     *
     * @param string $service
     * @param string $action
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $service, string $action, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverCli->executeServiceAction($service, $action);

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

