<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Exceptions\System\LcCliException;

interface UserDriverInterface
{
    /**
     * List users and their disk usage reported by lc-cli.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(): array;

    /**
     * Create a Linux system user.
     *
     * @throws LcCliException
     */
    public function createUser(LinuxUsername $username, string $password, int $quotaMb): array;

    /**
     * Set or clear SSH key for a user.
     *
     * @throws LcCliException
     */
    public function updateSshKey(LinuxUsername $username, ?string $sshKey): array;

    /**
     * Update disk quota for a user.
     *
     * @throws LcCliException
     */
    public function updateQuota(LinuxUsername $username, int $quotaMb): array;

    /**
     * Suspend a user.
     *
     * @throws LcCliException
     */
    public function suspendUser(LinuxUsername $username): array;

    /**
     * Unsuspend a user.
     *
     * @throws LcCliException
     */
    public function unsuspendUser(LinuxUsername $username): array;

    /**
     * Delete a system user.
     *
     * @throws LcCliException
     */
    public function deleteUser(LinuxUsername $username, bool $removeHome = true): array;
}
