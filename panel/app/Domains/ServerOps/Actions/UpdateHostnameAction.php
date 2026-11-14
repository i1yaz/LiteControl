<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class UpdateHostnameAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Update server hostname.
     *
     * @throws LcCliException
     */
    public function handle(string $hostname, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->setHostname($hostname);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'update_hostname',
            'command' => "lc-cli server hostname set {$hostname}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['hostname' => $hostname]),
        ]);

        return $result;
    }
}
