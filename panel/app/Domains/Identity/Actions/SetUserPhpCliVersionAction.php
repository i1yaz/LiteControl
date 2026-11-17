<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\Identity\ValueObjects\PhpCliVersion;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

class SetUserPhpCliVersionAction
{
    public function __construct(
        private UserDriverInterface $userDriver,
        private ?AuditLoggerInterface $auditLogger = null
    ) {}

    public function execute(User $user, PhpCliVersion $version, ?User $actor = null): User
    {
        $linuxUsername = new LinuxUsername($user->username);

        // OS-first mutation: throws PhpCliUpdateFailedException on failure before touching DB
        $this->userDriver->setPhpCliVersion($linuxUsername, $version);

        return DB::transaction(function () use ($user, $version, $actor): User {
            $previousVersion = $user->php_cli_version;
            $user->update(['php_cli_version' => $version->value]);

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor?->id,
                'action' => 'user.php_cli_version_updated',
                'command' => "set-user-php-cli.sh {$user->username} {$version->value}",
                'status' => 'success',
                'details' => [
                    'target_user_id' => $user->id,
                    'username' => $user->username,
                    'previous_version' => $previousVersion,
                    'new_version' => $version->value,
                ],
            ]);

            return $user->fresh();
        });
    }
}
