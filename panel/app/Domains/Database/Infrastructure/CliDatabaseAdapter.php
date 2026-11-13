<?php

namespace App\Domains\Database\Infrastructure;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliDatabaseAdapter implements DatabaseDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listDatabases(): array
    {
        $res = $this->lcCli->run('db', ['list']);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to list databases.', 'db', ['list'], $res);
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function createDatabase(DatabaseName $name): array
    {
        $args = ['create-db', $name->value];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create database '{$name->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function deleteDatabase(DatabaseName $name): array
    {
        $args = ['delete-db', $name->value];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete database '{$name->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function createUser(DatabaseUsername $username, string $password): array
    {
        $args = ['create-user', $username->value, $password];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create database user '{$username->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function deleteUser(DatabaseUsername $username): array
    {
        $args = ['delete-user', $username->value];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete database user '{$username->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function grantUser(DatabaseUsername $username, DatabaseName $database): array
    {
        $args = ['grant', $username->value, $database->value];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to grant privileges on '{$database->value}' to '{$username->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function changePassword(DatabaseUsername $username, string $password): array
    {
        $args = ['password', $username->value, $password];
        $res = $this->lcCli->run('db', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to change password for database user '{$username->value}'.", 'db', $args, $res);
        }

        return $res['data'] ?? [];
    }
}

