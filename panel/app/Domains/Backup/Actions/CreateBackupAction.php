<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Jobs\CreateBackupJob;
use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

class CreateBackupAction
{
    public function __construct(
        protected ?BackupDriverInterface $backupDriver = null,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    public function handle(User $targetUser, User $actor, ?string $ipAddress = null): Backup
    {
        $filename = 'backup-'.$targetUser->username.'-'.now()->format('Ymd_His').'.tar.gz';
        $path = "/home/{$targetUser->username}/backups/{$filename}";

        $backup = DB::transaction(function () use ($targetUser, $actor, $filename, $path, $ipAddress) {
            $backup = Backup::create([
                'user_id' => $targetUser->id,
                'filename' => $filename,
                'path' => $path,
                'size_bytes' => 0,
                'type' => 'manual',
                'status' => 'pending',
            ]);

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor->id,
                'action' => 'backup.create',
                'command' => "lc-cli backup create {$targetUser->username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['filename' => $filename, 'target_user' => $targetUser->username],
            ]);

            CreateBackupJob::dispatch($backup, $targetUser);

            return $backup;
        });

        return $backup->fresh() ?? $backup;
    }
}
