<?php

namespace App\Actions\Server;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class UpdateTimezoneAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Update server timezone.
     *
     * @param string $timezone
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $timezone, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverCli->setTimezone($timezone);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'update_timezone',
            'command' => "lc-cli server timezone set {$timezone}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['timezone' => $timezone]),
        ]);

        return $result;
    }
}

