<?php

namespace App\Actions\User;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use App\Services\System\UserCliService;
use Illuminate\Support\Facades\DB;

class UpdateUserAction
{
    public function __construct(
        protected UserCliService $userCli
    ) {}

    /**
     * Update user profile, package quota, and SSH keys.
     *
     * @param User $user
     * @param array $data
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return User
     */
    public function handle(User $user, array $data, ?User $actor = null, ?string $ipAddress = null): User
    {
        $packageChanged = (int) $user->package_id !== (int) $data['package_id'];
        $newPackage = null;

        if ($packageChanged) {
            $newPackage = Package::findOrFail($data['package_id']);
            $this->userCli->updateQuota($user->username, (int) $newPackage->disk_quota_mb);
        }

        $newKey = $data['ssh_public_key'] ?? null;
        $keyChanged = $newKey !== $user->ssh_public_key;

        if ($keyChanged) {
            $this->userCli->updateSshKey($user->username, $newKey);
        }

        DB::transaction(function () use ($user, $data, $packageChanged, $newPackage, $keyChanged, $newKey) {
            $user->name = $data['name'];
            $user->email = $data['email'];

            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }

            if ($packageChanged && $newPackage) {
                $user->package_id = $newPackage->id;
            }

            if ($keyChanged) {
                $user->ssh_public_key = $newKey;
            }

            $user->save();
        });

        if ($actor) {
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'user.update',
                'command' => "lc-cli user update {$user->username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['username' => $user->username, 'package_id' => $user->package_id],
            ]);
        }

        return $user;
    }
}

