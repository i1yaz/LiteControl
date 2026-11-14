<?php

declare(strict_types=1);

namespace App\Domains\Backup\Contracts;

use App\Domains\Backup\ValueObjects\BackupFrequency;
use App\Domains\Backup\ValueObjects\BackupPath;
use App\Domains\Backup\ValueObjects\RetentionCount;
use App\Exceptions\System\LcCliException;

interface BackupDriverInterface
{
    public function listBackups(?string $username = null): array;

    /**
     * @throws LcCliException
     */
    public function createBackup(string $username): array;

    /**
     * @throws LcCliException
     */
    public function restoreBackup(BackupPath $backupPath): array;

    /**
     * @throws LcCliException
     */
    public function addSchedule(string $username, BackupFrequency $frequency, RetentionCount $retentionCount): array;

    /**
     * @throws LcCliException
     */
    public function removeSchedule(string $username): array;
}
