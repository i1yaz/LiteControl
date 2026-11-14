<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class KillProcessAction
{
    public function __construct(
        protected ServerDriverInterface $serverDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Terminate a running process.
     *
     * @throws LcCliException
     */
    public function handle(int $pid, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->serverDriver->killProcess($pid);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
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
