<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Events\UserSuspended;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Exceptions\System\LcCliException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuspendUserAction
{
    public function __construct(
        protected UserDriverInterface $userDriver
    ) {}

    /**
     * Suspend a hosting user and dispatch UserSuspended.
     *
     * @throws InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(User $user, ?User $actor = null, ?string $ipAddress = null): User
    {
        if ($user->isAdmin()) {
            throw new InvalidArgumentException('Administrator accounts cannot be suspended.');
        }

        $username = new LinuxUsername($user->username);
        $this->userDriver->suspendUser($username);

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

        UserSuspended::dispatch($user->id, $user->username);

        return $user;
    }
}

