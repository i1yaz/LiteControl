<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class BackupCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * List backups reported by lc-cli.
     *
     * @param string|null $username
     * @return array
     */
    public function listBackups(?string $username = null): array
    {
        $args = ['list'];
        if (! empty($username)) {
            $args[] = $username;
        } else {
            $args[] = '';
        }

        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Create an on-demand backup for a user via lc-cli.
     *
     * @param string $username
     * @return array
     * @throws LcCliException
     */
    public function createBackup(string $username): array
    {
        $args = ['create', $username];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create backup for '{$username}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Restore a backup archive from path via lc-cli.
     *
     * @param string $backupPath
     * @return array
     * @throws LcCliException
     */
    public function restoreBackup(string $backupPath): array
    {
        $args = ['restore', $backupPath];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to restore backup from '{$backupPath}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Add an automated backup schedule via lc-cli.
     *
     * @param string $username
     * @param string $frequency
     * @param int $retentionCount
     * @return array
     * @throws LcCliException
     */
    public function addSchedule(string $username, string $frequency, int $retentionCount): array
    {
        $args = ['schedule', 'add', $username, $frequency, (string) $retentionCount];
        $res = $this->lcCli->run('backup', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to register backup schedule for '{$username}'.", 'backup', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Remove an automated backup schedule via lc-cli.
     *
     * @param string $username
     * @return array
     * @throws LcCliException
     */
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

