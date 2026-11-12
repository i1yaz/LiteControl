<?php

namespace App\Actions\Backup;

use App\Models\AuditLog;
use App\Models\BackupSchedule;
use App\Models\User;
use App\Services\System\BackupCliService;
use Illuminate\Support\Facades\DB;

class RemoveBackupScheduleAction
{
    public function __construct(
        protected BackupCliService $backupCli
    ) {}

    /**
     * Remove automated backup schedule from system and database.
     *
     * @param BackupSchedule $schedule
     * @param User $actor
     * @param string|null $ipAddress
     * @return void
     */
    public function handle(BackupSchedule $schedule, User $actor, ?string $ipAddress = null): void
    {
        $targetUsername = $schedule->user->username ?? $actor->username;

        // Deregister schedule from lc-cli first
        $this->backupCli->removeSchedule($targetUsername);

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

