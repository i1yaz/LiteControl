<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class ToggleWebAdminAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver
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

        AuditLog::create([
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

        AuditLog::create([
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

