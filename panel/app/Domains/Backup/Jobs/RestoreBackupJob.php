<?php

declare(strict_types=1);

namespace App\Domains\Backup\Jobs;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\Backup;
use App\Domains\Shared\ValueObjects\SystemPath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SystemPath $systemPath,
        public readonly ?Backup $backup = null,
    ) {
        $this->afterCommit();
    }

    public function handle(BackupDriverInterface $backupDriver): void
    {
        $this->backup?->update(['status' => 'provisioning']);

        try {
            $backupDriver->restoreBackup($this->systemPath);
            $this->backup?->update(['status' => 'active']);
        } catch (\Throwable $e) {
            $this->backup?->update(['status' => 'failed']);
            throw $e;
        }
    }
}
