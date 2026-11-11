<?php

namespace App\Actions\Backup;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\BackupCliService;

class RestoreBackupAction
{
    public function __construct(
        protected BackupCliService $backupCli
    ) {}

    /**
     * Restore an existing backup archive from path.
     *
     * @param string $backupPath
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     */
    public function handle(string $backupPath, User $actor, ?string $ipAddress = null): array
    {
        $res = $this->backupCli->restoreBackup($backupPath);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'backup.restore',
            'command' => "lc-cli backup restore {$backupPath}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['path' => $backupPath],
        ]);

        return $res;
    }
}

