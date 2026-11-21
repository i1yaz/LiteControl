<?php

declare(strict_types=1);

namespace App\Domains\Backup\Jobs;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateBackupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Backup $backup,
        public readonly User $targetUser,
    ) {
        $this->afterCommit();
    }

    public function handle(
        BackupDriverInterface $backupDriver,
        ModuleRegistryQueryInterface $moduleRegistry,
    ): void {
        $this->backup->update(['status' => 'provisioning']);

        try {
            $data = $moduleRegistry->isActive('mail_core')
                ? $backupDriver->createBackup($this->targetUser->username, true)
                : $backupDriver->createBackup($this->targetUser->username);

            $filename = $data['filename'] ?? $this->backup->filename;
            $path = $data['path'] ?? $this->backup->path;
            $size = $data['size_bytes'] ?? 0;

            $this->backup->update([
                'filename' => $filename,
                'path' => $path,
                'size_bytes' => $size,
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            $this->backup->update(['status' => 'failed']);
            throw $e;
        }
    }
}
