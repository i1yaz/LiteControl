<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Events\UserCreated;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use App\Domains\PanelConfig\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class CreateUserAction
{
    public function __construct(
        protected UserDriverInterface $userDriver
    ) {}

    /**
     * Create a new hosting user, provision Linux user & quota, and optional primary domain.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, domainCreated: bool, warning: ?string}
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): array
    {
        $username = new LinuxUsername($data['username']);
        $package = Package::findOrFail($data['package_id']);

        // 1. Create Linux user via UserDriverInterface (throws LcCliException on failure)
        $this->userDriver->createUser($username, $data['password'], (int) $package->disk_quota_mb);

        // 2. Set SSH public key if provided
        if (! empty($data['ssh_public_key'])) {
            try {
                $this->userDriver->updateSshKey($username, $data['ssh_public_key']);
            } catch (\Throwable) {
                // Non-fatal
            }
        }

        // 3. Create database user record
        $user = DB::transaction(function () use ($data, $username, $package) {
            return User::create([
                'username' => $username->value,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'user',
                'package_id' => $package->id,
                'home_directory' => "/home/{$username->value}",
                'status' => 'active',
                'ssh_public_key' => $data['ssh_public_key'] ?? null,
            ]);
        });

        $event = new UserCreated($user, $data, $actor);
        event($event);

        $domainCreated = $event->domainCreated;
        $warning = $event->warning;

        if ($actor) {
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'user.create',
                'command' => "lc-cli user add {$user->username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'username' => $user->username,
                    'package' => $package->name,
                    'domain' => $data['domain'] ?? null,
                ],
            ]);
        }

        return [
            'user' => $user,
            'domainCreated' => $domainCreated,
            'warning' => $warning,
        ];
    }
}
