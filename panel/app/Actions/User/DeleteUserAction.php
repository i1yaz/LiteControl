<?php

namespace App\Actions\User;

use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use App\Services\System\UserCliService;
use Illuminate\Support\Facades\DB;

class DeleteUserAction
{
    public function __construct(
        protected UserCliService $userCli
    ) {}

    /**
     * Delete a hosting user and all their associated records.
     *
     * @param User $user
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return void
     * @throws \InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): void
    {
        if (($actor && $user->id === $actor->id) || $user->isAdmin()) {
            throw new \InvalidArgumentException('Cannot delete active administrator account.');
        }

        $username = $user->username;

        // 1. Remove linux user and home files via CLI
        $this->userCli->deleteUser($username, true);

        // 2. Clean up database records
        DB::transaction(function () use ($user, $username, $actor, $ipAddress) {
            $user->domains()->delete();
            $user->databases()->delete();
            $user->databaseUsers()->delete();
            $user->backups()->delete();
            $user->delete();

            if ($actor) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => 'user.delete',
                    'command' => "lc-cli user delete {$username} --remove-home",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => ['username' => $username],
                ]);
            }
        });
    }
}

