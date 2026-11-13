<?php

declare(strict_types=1);

namespace App\Domains\Identity\Infrastructure;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliUserAdapter implements UserDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listUsers(): array
    {
        $res = $this->lcCli->run('user', ['list']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function createUser(LinuxUsername $username, string $password, int $quotaMb): array
    {
        $args = ['add', $username->value, $password, (string) $quotaMb];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to create system user '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function updateSshKey(LinuxUsername $username, ?string $sshKey): array
    {
        $keyParam = ! empty($sshKey) ? $sshKey : 'clear';
        $args = ['ssh-key', $username->value, $keyParam];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to update SSH key for '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function updateQuota(LinuxUsername $username, int $quotaMb): array
    {
        $args = ['quota', $username->value, (string) $quotaMb];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to update quota for '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function suspendUser(LinuxUsername $username): array
    {
        $args = ['suspend', $username->value];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to suspend user '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function unsuspendUser(LinuxUsername $username): array
    {
        $args = ['unsuspend', $username->value];
        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to unsuspend user '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }

    public function deleteUser(LinuxUsername $username, bool $removeHome = true): array
    {
        $args = ['delete', $username->value];
        if ($removeHome) {
            $args[] = '--remove-home';
        }

        $res = $this->lcCli->run('user', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to delete user '{$username->value}'.", 'user', $args, $res);
        }

        return $res['data'] ?? [];
    }
}

