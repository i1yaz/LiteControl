<?php

namespace App\Actions\Server;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class ToggleWebAdminAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Enable OpenLiteSpeed WebAdmin console.
     *
     * @param string|null $allowedIps
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function enable(?string $allowedIps = null, ?User $actor = null, ?string $ipAddress = null): array
    {
        $ips = trim($allowedIps ?? '');
        $result = $this->serverCli->enableWebAdmin($ips);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'webadmin_enabled',
            'command' => 'lc-cli server webadmin-enable' . ($ips !== '' ? " {$ips}" : ''),
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['allowed_ips' => $ips]),
        ]);

        return $result;
    }

    /**
     * Disable OpenLiteSpeed WebAdmin console.
     *
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function disable(?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverCli->disableWebAdmin();

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

