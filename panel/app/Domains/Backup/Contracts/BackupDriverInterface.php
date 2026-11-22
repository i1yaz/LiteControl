<?php

declare(strict_types=1);

namespace App\Domains\Backup\Contracts;

use App\Domains\Backup\ValueObjects\BackupFrequency;
use App\Domains\Backup\ValueObjects\RetentionCount;
use App\Domains\Shared\ValueObjects\SystemPath;
use App\Exceptions\System\LcCliException;

interface BackupDriverInterface
{
    public function listBackups(?string $username = null): array;

    /**
     * @throws LcCliException
     */
    public function createBackup(string $username, bool $includeMail = false): array;

    /**
     * @throws LcCliException
     */
    public function restoreBackup(SystemPath $SystemPath): array;

    /**
     * @throws LcCliException
     */
    public function addSchedule(string $username, BackupFrequency $frequency, RetentionCount $retentionCount): array;

    /**
     * @throws LcCliException
     */
    public function removeSchedule(string $username): array;
}
