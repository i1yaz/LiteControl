<?php

namespace App\Actions\User;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use App\Services\DomainService;
use App\Services\System\UserCliService;
use Illuminate\Support\Facades\DB;

class CreateUserAction
{
    public function __construct(
        protected UserCliService $userCli,
        protected DomainService $domainService
    ) {}

    /**
     * Create a new hosting user, provision Linux user & quota, and optional primary domain.
     *
     * @param array $data
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array ['user' => User, 'domainCreated' => bool, 'warning' => ?string]
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): array
    {
        $package = Package::findOrFail($data['package_id']);

        // 1. Create Linux user via UserCliService (throws LcCliException on failure)
        $this->userCli->createUser($data['username'], $data['password'], (int) $package->disk_quota_mb);

        // 2. Set SSH public key if provided
        if (!empty($data['ssh_public_key'])) {
            try {
                $this->userCli->updateSshKey($data['username'], $data['ssh_public_key']);
            } catch (\Throwable $e) {
                // Non-fatal, or logged
            }
        }

        // 3. Create database user record
        $user = DB::transaction(function () use ($data, $package) {
            return User::create([
                'username' => $data['username'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'user',
                'package_id' => $package->id,
                'home_directory' => "/home/{$data['username']}",
                'status' => 'active',
                'ssh_public_key' => $data['ssh_public_key'] ?? null,
            ]);
        });

        $warning = null;
        $domainCreated = false;

        // 4. Optionally create primary domain if provided
        if (!empty($data['domain'])) {
            try {
                $domainRes = $this->domainService->createDomain($user, [
                    'domain' => $data['domain'],
                    'php_version' => $data['php_version'] ?? '83',
                    'public_path' => $data['public_path'] ?? null,
                    'ssl_enabled' => $data['ssl_enabled'] ?? true,
                    'type' => 'primary',
                ], $actor);

                $domainCreated = true;
                $warning = $domainRes['warning'] ?? null;
            } catch (\Throwable $e) {
                $warning = "User created, but domain provisioning encountered an error: " . $e->getMessage();
            }
        }

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

