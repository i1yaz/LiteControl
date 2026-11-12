<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Services\LcCliService;

class UserCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * List users and their disk usage reported by lc-cli.
     *
     * @return array
     */
    public function listUsers(): array
    {
        $res = $this->lcCli->run('user', ['list']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Create a Linux system user via lc-cli.
     *
     * @param string $username
     * @param string $password
     * @param int $quotaMb
     * @return array
     * @throws LcCliException
     */
    public function createUser(string $username, string $password, int $quotaMb): array
    {
        $args = ['add', $username, $password, (string) $quotaMb];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create system user '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Set or clear SSH key for a user via lc-cli.
     *
     * @param string $username
     * @param string|null $sshKey
     * @return array
     * @throws LcCliException
     */
    public function updateSshKey(string $username, ?string $sshKey): array
    {
        $keyParam = !empty($sshKey) ? $sshKey : 'clear';
        $args = ['ssh-key', $username, $keyParam];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to update SSH key for '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Update disk quota for a user via lc-cli.
     *
     * @param string $username
     * @param int $quotaMb
     * @return array
     * @throws LcCliException
     */
    public function updateQuota(string $username, int $quotaMb): array
    {
        $args = ['quota', $username, (string) $quotaMb];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to update quota for '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Suspend a user via lc-cli.
     *
     * @param string $username
     * @return array
     * @throws LcCliException
     */
    public function suspendUser(string $username): array
    {
        $args = ['suspend', $username];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to suspend user '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Unsuspend a user via lc-cli.
     *
     * @param string $username
     * @return array
     * @throws LcCliException
     */
    public function unsuspendUser(string $username): array
    {
        $args = ['unsuspend', $username];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to unsuspend user '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Delete a system user via lc-cli.
     *
     * @param string $username
     * @param bool $removeHome
     * @return array
     * @throws LcCliException
     */
    public function deleteUser(string $username, bool $removeHome = true): array
    {
        $args = ['delete', $username];
        if ($removeHome) {
            $args[] = '--remove-home';
        }

        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete user '{$username}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }
}

