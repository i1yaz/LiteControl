<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class FtpService
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Create an FTP account tied to a domain.
     *
     * @param Domain $domain
     * @param array $data ['username' => string, 'password' => string, 'dir' => ?string, 'quota_mb' => ?int]
     * @param User|null $actor
     * @return FtpAccount
     * @throws \RuntimeException
     */
    public function createAccount(Domain $domain, array $data, ?User $actor = null): FtpAccount
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        // Check package quota if user is not admin
        if (! $actor->isAdmin() && $user->package) {
            $currentFtpCount = FtpAccount::where('user_id', $user->id)->count();
            if ($currentFtpCount >= $user->package->max_ftp_accounts) {
                throw new \RuntimeException("Package FTP account limit reached ({$user->package->max_ftp_accounts}).");
            }
        }

        $rawUsername = strtolower(trim($data['username']));
        $prefix = $user->username . '_';
        $finalUsername = str_starts_with($rawUsername, $prefix) ? $rawUsername : $prefix . $rawUsername;

        if (FtpAccount::where('username', $finalUsername)->exists()) {
            throw new \RuntimeException("FTP user '{$finalUsername}' already exists.");
        }

        // Sanitize subpath directory inside domain root
        $rawSubdir = trim($data['dir'] ?? '', " /\\");
        $domainBase = "/home/{$user->username}/{$domain->domain}";
        $fullFtpDir = empty($rawSubdir) ? "{$domainBase}/public_html" : "{$domainBase}/public_html/{$rawSubdir}";

        // Ensure directory exists via lc-cli run if needed, or create safely
        if (! file_exists($fullFtpDir)) {
            @mkdir($fullFtpDir, 0755, true);
        }

        // Resolve system UID/GID (defaulting to 5001 or linux user uid)
        $uid = 5001;
        $gid = 5001;
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($user->username);
            if ($pw) {
                $uid = (int) $pw['uid'];
                $gid = (int) $pw['gid'];
            }
        }

        // Pure-FTPd MySQL uses Argon2 / password_hash
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        $ftpAccount = FtpAccount::create([
            'user_id' => $user->id,
            'domain_id' => $domain->id,
            'username' => $finalUsername,
            'password_hash' => $passwordHash,
            'uid' => $uid,
            'gid' => $gid,
            'dir' => $fullFtpDir,
            'quota_mb' => (int) ($data['quota_mb'] ?? 0),
            'status' => 'active',
        ]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'ftp.create',
            'command' => "ftp create {$finalUsername}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => [
                'username' => $finalUsername,
                'domain' => $domain->domain,
                'dir' => $fullFtpDir,
            ],
        ]);

        return $ftpAccount;
    }

    /**
     * Delete an FTP account.
     *
     * @param FtpAccount $ftpAccount
     * @param User|null $actor
     * @return bool
     */
    public function deleteAccount(FtpAccount $ftpAccount, ?User $actor = null): bool
    {
        $actor = $actor ?? Auth::user();
        $name = $ftpAccount->username;
        $ftpAccount->delete();

        AuditLog::create([
            'user_id' => $actor->id ?? 1,
            'action' => 'ftp.delete',
            'command' => "ftp delete {$name}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['username' => $name],
        ]);

        return true;
    }
}

