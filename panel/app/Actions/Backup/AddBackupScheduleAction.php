<?php

namespace App\Actions\Backup;

use App\Models\AuditLog;
use App\Models\BackupSchedule;
use App\Models\User;
use App\Services\System\BackupCliService;
use Illuminate\Support\Facades\DB;

class AddBackupScheduleAction
{
    public function __construct(
        protected BackupCliService $backupCli
    ) {}

    /**
     * Create an automated backup schedule and register it with CLI.
     *
     * @param User $targetUser
     * @param string $frequency
     * @param int $retentionCount
     * @param string $destination
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return BackupSchedule
     */
    public function handle(
        User $targetUser,
        string $frequency,
        int $retentionCount,
        string $destination = 'local',
        ?User $actor = null,
        ?string $ipAddress = null
    ): BackupSchedule {
        $actor = $actor ?? $targetUser;

        return DB::transaction(function () use ($targetUser, $frequency, $retentionCount, $destination, $actor, $ipAddress) {
            $schedule = BackupSchedule::create([
                'user_id' => $targetUser->id,
                'frequency' => $frequency,
                'retention_count' => $retentionCount,
                'destination' => $destination,
                'is_active' => true,
            ]);

            // Register schedule with lc-cli
            $this->backupCli->addSchedule($targetUser->username, $frequency, $retentionCount);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'backup.schedule.add',
                'command' => "lc-cli backup schedule add {$targetUser->username} {$frequency} {$retentionCount}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'frequency' => $frequency,
                    'retention_count' => $retentionCount,
                    'destination' => $destination,
                    'target_user' => $targetUser->username,
                ],
            ]);

            return $schedule;
        });
    }
}

