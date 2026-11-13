<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class DatabaseCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * List all databases with sizes and table counts.
     *
     * @return array
     * @throws LcCliException
     */
    public function listDatabases(): array
    {
        $res = $this->lcCli->run('db', ['list']);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to list databases.', 'db', ['list'], $res);
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    /**
     * Create a database via lc-cli.
     *
     * @param string $name
     * @return array
     * @throws LcCliException
     */
    public function createDatabase(string $name): array
    {
        $args = ['create-db', $name];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create database '{$name}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Delete a database via lc-cli.
     *
     * @param string $name
     * @return array
     * @throws LcCliException
     */
    public function deleteDatabase(string $name): array
    {
        $args = ['delete-db', $name];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete database '{$name}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Create a database user via lc-cli.
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws LcCliException
     */
    public function createUser(string $username, string $password): array
    {
        $args = ['create-user', $username, $password];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create database user '{$username}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Delete a database user via lc-cli.
     *
     * @param string $username
     * @return array
     * @throws LcCliException
     */
    public function deleteUser(string $username): array
    {
        $args = ['delete-user', $username];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete database user '{$username}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Grant database privileges to a database user via lc-cli.
     *
     * @param string $username
     * @param string $database
     * @return array
     * @throws LcCliException
     */
    public function grantUser(string $username, string $database): array
    {
        $args = ['grant', $username, $database];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to grant privileges on '{$database}' to '{$username}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Change a database user password via lc-cli.
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws LcCliException
     */
    public function changePassword(string $username, string $password): array
    {
        $args = ['password', $username, $password];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to change password for database user '{$username}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }
}

