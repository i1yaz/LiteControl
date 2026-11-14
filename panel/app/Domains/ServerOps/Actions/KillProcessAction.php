<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;

class KillProcessAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver
    ) {}

    /**
     * Terminate a running process.
     *
     * @throws LcCliException
     */
    public function handle(int $pid, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->killProcess($pid);

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
