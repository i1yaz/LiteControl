<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class ToggleWebAdminAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Enable OpenLiteSpeed WebAdmin console.
     *
     * @throws LcCliException
     */
    public function enable(?string $allowedIps = null, ?User $actor = null, ?string $ipAddress = null): array
    {
        $ips = trim($allowedIps ?? '');
        $result = $this->serverDriver->enableWebAdmin($ips);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'webadmin_enabled',
            'command' => 'lc-cli server webadmin-enable'.($ips !== '' ? " {$ips}" : ''),
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['allowed_ips' => $ips]),
        ]);

        return $result;
    }

    /**
     * Disable OpenLiteSpeed WebAdmin console.
     *
     * @throws LcCliException
     */
    public function disable(?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->disableWebAdmin();

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'webadmin_disabled',
            'command' => 'lc-cli server webadmin-disable',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([]),
        ]);

        return $result;
    }
}
