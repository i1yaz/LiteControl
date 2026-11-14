<?php

declare(strict_types=1);

namespace App\Domains\Backup\Actions;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\ValueObjects\SystemPath;

class RestoreBackupAction
{
    public function __construct(
        protected BackupDriverInterface $backupDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    public function handle(SystemPath $SystemPath, User $actor, ?string $ipAddress = null): array
    {
        $res = $this->backupDriver->restoreBackup($SystemPath);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'backup.restore',
            'command' => "lc-cli backup restore {$SystemPath->fullPath}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['path' => $SystemPath->fullPath],
        ]);

        return $res;
    }
}
