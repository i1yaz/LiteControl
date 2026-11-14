<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class UpdateTimezoneAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Update server timezone.
     *
     * @throws LcCliException
     */
    public function handle(string $timezone, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->setTimezone($timezone);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
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
