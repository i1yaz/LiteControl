<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class ManageServiceAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Execute a service management action (start, stop, restart, reload).
     *
     * @throws LcCliException
     */
    public function handle(string $service, string $action, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->executeServiceAction($service, $action);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
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
