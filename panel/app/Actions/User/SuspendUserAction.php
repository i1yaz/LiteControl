<?php

namespace App\Actions\User;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\UserCliService;
use Illuminate\Support\Facades\DB;

class SuspendUserAction
{
    public function __construct(
        protected UserCliService $userCli
    ) {}

    /**
     * Suspend a hosting user.
     *
     * @param User $user
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return User
     * @throws \InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): User
    {
        if ($user->isAdmin()) {
            throw new \InvalidArgumentException('Administrator accounts cannot be suspended.');
        }

        $this->userCli->suspendUser($user->username);

        DB::transaction(function () use ($user, $actor, $ipAddress) {
            $user->update(['status' => 'suspended']);

            if ($actor) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => 'user.suspend',
                    'command' => "lc-cli user suspend {$user->username}",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => ['username' => $user->username],
                ]);
            }
        });

        return $user;
    }
}

