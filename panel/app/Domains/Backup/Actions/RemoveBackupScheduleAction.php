<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\BackupSchedule;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveBackupScheduleAction
{
    public function __construct(
        protected BackupDriverInterface $backupDriver
    ) {}

    public function handle(BackupSchedule $schedule, User $actor, ?string $ipAddress = null): void
    {
        $targetUsername = $schedule->user->username ?? $actor->username;

        $this->backupDriver->removeSchedule($targetUsername);

        DB::transaction(function () use ($schedule, $actor, $targetUsername, $ipAddress) {
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'backup.schedule.remove',
                'command' => "lc-cli backup schedule remove {$targetUsername}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'schedule_id' => $schedule->id,
                    'target_user' => $targetUsername,
                ],
            ]);

            $schedule->delete();
        });
    }
}

