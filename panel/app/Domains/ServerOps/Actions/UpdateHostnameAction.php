<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;

class UpdateHostnameAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver
    ) {}

    /**
     * Update server hostname.
     *
     * @throws LcCliException
     */
    public function handle(string $hostname, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->setHostname($hostname);

        AuditLog::create([
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

