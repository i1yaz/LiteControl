<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\Backup;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBackupAction
{
    public function __construct(
        protected BackupDriverInterface $backupDriver
    ) {}

    public function handle(User $targetUser, User $actor, ?string $ipAddress = null): Backup
    {
        $data = $this->backupDriver->createBackup($targetUser->username);

        $filename = $data['filename'] ?? ('backup-' . $targetUser->username . '-' . now()->format('Ymd_His') . '.tar.gz');
        $path = $data['path'] ?? ("/home/{$targetUser->username}/backups/{$filename}");
        $size = $data['size_bytes'] ?? 0;

        return DB::transaction(function () use ($targetUser, $actor, $filename, $path, $size, $ipAddress) {
            $backup = Backup::create([
                'user_id' => $targetUser->id,
                'filename' => $filename,
                'path' => $path,
                'size_bytes' => $size,
                'type' => 'manual',
                'status' => 'completed',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'backup.create',
                'command' => "lc-cli backup create {$targetUser->username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['filename' => $filename, 'target_user' => $targetUser->username],
            ]);

            return $backup;
        });
    }
}

