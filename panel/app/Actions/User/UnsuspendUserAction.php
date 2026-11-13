<?php

namespace App\Actions\User;

use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use App\Services\System\UserCliService;
use Illuminate\Support\Facades\DB;

class UnsuspendUserAction
{
    public function __construct(
        protected UserCliService $userCli
    ) {}

    /**
     * Unsuspend a hosting user.
     *
     * @param User $user
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return User
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): User
    {
        $this->userCli->unsuspendUser($user->username);

        DB::transaction(function () use ($user, $actor, $ipAddress) {
            $user->update(['status' => 'active']);

            if ($actor) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => 'user.unsuspend',
                    'command' => "lc-cli user unsuspend {$user->username}",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => ['username' => $user->username],
                ]);
            }
        });

        return $user;
    }
}

