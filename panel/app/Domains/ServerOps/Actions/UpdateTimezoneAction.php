<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class UpdateTimezoneAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver
    ) {}

    /**
     * Update server timezone.
     *
     * @throws LcCliException
     */
    public function handle(string $timezone, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->setTimezone($timezone);

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

