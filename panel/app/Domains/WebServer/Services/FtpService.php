<?php

namespace App\Domains\WebServer\Services;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Support\Facades\Auth;

class FtpService
{
    /**
     * Create an FTP account tied to a domain.
     *
     * @throws \RuntimeException
     */
    public function createAccount(Domain $domain, array $data, ?User $actor = null): FtpAccount
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        if (! $actor->isAdmin() && $user->package) {
            $currentFtpCount = FtpAccount::where('user_id', $user->id)->count();
            if ($currentFtpCount >= $user->package->max_ftp_accounts) {
                throw new \RuntimeException("Package FTP account limit reached ({$user->package->max_ftp_accounts}).");
            }
        }

        $rawUsername = strtolower(trim($data['username']));
        $prefix = $user->username.'_';
        $finalUsername = str_starts_with($rawUsername, $prefix) ? $rawUsername : $prefix.$rawUsername;

        if (FtpAccount::where('username', $finalUsername)->exists()) {
            throw new \RuntimeException("FTP user '{$finalUsername}' already exists.");
        }

        $rawSubdir = trim($data['dir'] ?? '', ' /\\');
        $domainBase = "/home/{$user->username}/{$domain->domain}";
        $fullFtpDir = empty($rawSubdir) ? "{$domainBase}/public_html" : "{$domainBase}/public_html/{$rawSubdir}";

        if (! file_exists($fullFtpDir)) {
            @mkdir($fullFtpDir, 0755, true);
        }

        $uid = 5001;
        $gid = 5001;
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($user->username);
            if ($pw) {
                $uid = (int) $pw['uid'];
                $gid = (int) $pw['gid'];
            }
        }

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
