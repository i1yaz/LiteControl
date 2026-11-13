<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Exceptions\System\LcCliException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnsuspendUserAction
{
    public function __construct(
        protected UserDriverInterface $userDriver
    ) {}

    /**
     * Unsuspend a hosting user.
     *
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): User
    {
        $username = new LinuxUsername($user->username);
        $this->userDriver->unsuspendUser($username);

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

