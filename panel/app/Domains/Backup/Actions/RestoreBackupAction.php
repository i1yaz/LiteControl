<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\ValueObjects\BackupPath;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class RestoreBackupAction
{
    public function __construct(
        protected BackupDriverInterface $backupDriver
    ) {}

    public function handle(BackupPath $backupPath, User $actor, ?string $ipAddress = null): array
    {
        $res = $this->backupDriver->restoreBackup($backupPath);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'backup.restore',
            'command' => "lc-cli backup restore {$backupPath->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['path' => $backupPath->value],
        ]);

        return $res;
    }
}

