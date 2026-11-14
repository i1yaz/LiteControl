<?php

namespace App\Domains\Database\Contracts;

use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Exceptions\System\LcCliException;

interface DatabaseDriverInterface
{
    /**
     * List all databases with sizes and table counts.
     *
     * @throws LcCliException
     */
    public function listDatabases(): array;

    /**
     * Create a database via lc-cli.
     *
     * @throws LcCliException
     */
    public function createDatabase(DatabaseName $name): array;

    /**
     * Delete a database via lc-cli.
     *
     * @throws LcCliException
     */
    public function deleteDatabase(DatabaseName $name): array;

    /**
     * Create a database user via lc-cli.
     *
     * @throws LcCliException
     */
    public function createUser(DatabaseUsername $username, string $password): array;

    /**
     * Delete a database user via lc-cli.
     *
     * @throws LcCliException
     */
    public function deleteUser(DatabaseUsername $username): array;

    /**
     * Grant database privileges to a database user via lc-cli.
     *
     * @throws LcCliException
     */
    public function grantUser(DatabaseUsername $username, DatabaseName $database): array;

    /**
     * Change a database user password via lc-cli.
     *
     * @throws LcCliException
     */
    public function changePassword(DatabaseUsername $username, string $password): array;
}
