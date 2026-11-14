<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Events\UserDeleted;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteUserAction
{
    public function __construct(
        protected UserDriverInterface $userDriver
    ) {}

    /**
     * Delete a hosting user and dispatch UserDeleted for cross-domain cleanup.
     *
     * @throws InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): void
    {
        if (($actor && $user->id === $actor->id) || $user->isAdmin()) {
            throw new InvalidArgumentException('Cannot delete active administrator account.');
        }

        $userId = $user->id;
        $username = new LinuxUsername($user->username);

        // 1. Remove linux user and home files via driver
        $this->userDriver->deleteUser($username, true);

        // 2. Delete user record and audit log; cross-domain records are cleaned up via UserDeleted listeners
        DB::transaction(function () use ($user, $username, $actor, $ipAddress) {
            $user->delete();

            if ($actor) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => 'user.delete',
                    'command' => "lc-cli user delete {$username->value} --remove-home",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => ['username' => $username->value],
                ]);
            }
        });

        UserDeleted::dispatch($userId, $username->value);
    }
}
