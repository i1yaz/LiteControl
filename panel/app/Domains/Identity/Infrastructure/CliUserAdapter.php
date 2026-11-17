<?php

declare(strict_types=1);

namespace App\Domains\Identity\Infrastructure;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Exceptions\PhpCliUpdateFailedException;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\Identity\ValueObjects\PhpCliVersion;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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

    public function setPhpCliVersion(LinuxUsername $username, PhpCliVersion $version): void
    {
        $result = Process::timeout(5)->run([
            '/usr/local/lib/lc-panel/set-user-php-cli.sh',
            $username->value,
            $version->value,
        ]);

        if (! $result->successful()) {
            Log::error('Failed to set user PHP CLI version', [
                'username' => $username->value,
                'requested_version' => $version->value,
                'exit_code' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
            ]);

            throw new PhpCliUpdateFailedException('Could not switch PHP CLI version on the host.');
        }
    }

    public function removePhpCliConfig(LinuxUsername $username): void
    {
        $path = '/etc/lc-panel/php-cli/users/'.$username->value;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
