<?php

namespace App\Actions\Server;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class KillProcessAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Terminate a running process.
     *
     * @param int $pid
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(int $pid, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverCli->killProcess($pid);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'kill_process',
            'command' => "lc-cli server kill-process {$pid}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['pid' => $pid]),
        ]);

        return $result;
    }
}

