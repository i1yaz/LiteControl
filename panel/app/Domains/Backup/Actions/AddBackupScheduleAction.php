<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\BackupSchedule;
use App\Domains\Backup\ValueObjects\BackupFrequency;
use App\Domains\Backup\ValueObjects\RetentionCount;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddBackupScheduleAction
{
    public function __construct(
        protected BackupDriverInterface $backupDriver
    ) {}

    public function handle(
        User $targetUser,
        BackupFrequency $frequency,
        RetentionCount $retentionCount,
        string $destination = 'local',
        ?User $actor = null,
        ?string $ipAddress = null
    ): BackupSchedule {
        $actor = $actor ?? $targetUser;

        return DB::transaction(function () use ($targetUser, $frequency, $retentionCount, $destination, $actor, $ipAddress) {
            $schedule = BackupSchedule::create([
                'user_id' => $targetUser->id,
                'frequency' => $frequency->value,
                'retention_count' => $retentionCount->value,
                'destination' => $destination,
                'is_active' => true,
            ]);

            $this->backupDriver->addSchedule($targetUser->username, $frequency, $retentionCount);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'backup.schedule.add',
                'command' => "lc-cli backup schedule add {$targetUser->username} {$frequency->value} {$retentionCount->value}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'frequency' => $frequency->value,
                    'retention_count' => $retentionCount->value,
                    'destination' => $destination,
                    'target_user' => $targetUser->username,
                ],
            ]);

            return $schedule;
        });
    }
}

