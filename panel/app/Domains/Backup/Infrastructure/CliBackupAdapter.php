<?php

declare(strict_types=1);

namespace App\Domains\Backup\Infrastructure;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\ValueObjects\BackupFrequency;
use App\Domains\Backup\ValueObjects\RetentionCount;
use App\Domains\Shared\ValueObjects\SystemPath;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliBackupAdapter implements BackupDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listBackups(?string $username = null): array
    {
        $args = ['list', ! empty($username) ? $username : ''];

        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function createBackup(string $username): array
    {
        $args = ['create', $username];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create backup for '{$username}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function restoreBackup(SystemPath $SystemPath): array
    {
        $args = ['restore', $SystemPath->value];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to restore backup from '{$SystemPath->value}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function addSchedule(string $username, BackupFrequency $frequency, RetentionCount $retentionCount): array
    {
        $args = ['schedule', 'add', $username, $frequency->value, (string) $retentionCount->value];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to register backup schedule for '{$username}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function removeSchedule(string $username): array
    {
        $args = ['schedule', 'remove', $username];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to remove backup schedule for '{$username}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }
}
