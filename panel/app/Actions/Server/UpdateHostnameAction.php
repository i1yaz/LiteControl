<?php

namespace App\Actions\Server;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class UpdateHostnameAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Update server hostname.
     *
     * @param string $hostname
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $hostname, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverCli->setHostname($hostname);

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

