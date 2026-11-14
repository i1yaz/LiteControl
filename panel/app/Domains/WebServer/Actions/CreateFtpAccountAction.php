<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\FtpDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateFtpAccountAction
{
    public function __construct(
        protected FtpDriverInterface $ftpDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Create an FTP account tied to a domain.
     *
     * @throws RuntimeException
     */
    public function handle(Domain $domain, array $data, ?User $actor = null): FtpAccount
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        if (! $actor->isAdmin() && $user->package) {
            $currentFtpCount = FtpAccount::where('user_id', $user->id)->count();
            if ($currentFtpCount >= $user->package->max_ftp_accounts) {
                throw new RuntimeException("Package FTP account limit reached ({$user->package->max_ftp_accounts}).");
            }
        }

        $rawUsername = strtolower(trim($data['username']));
        $prefix = $user->username.'_';
        $finalUsername = str_starts_with($rawUsername, $prefix) ? $rawUsername : $prefix.$rawUsername;

        if (FtpAccount::where('username', $finalUsername)->exists()) {
            throw new RuntimeException("FTP user '{$finalUsername}' already exists.");
        }

        $rawSubdir = trim($data['dir'] ?? '', ' /\\');
        $domainBase = "/home/{$user->username}/{$domain->domain}";
        $fullFtpDir = empty($rawSubdir) ? "{$domainBase}/public_html" : "{$domainBase}/public_html/{$rawSubdir}";

        $this->ftpDriver->ensureDirectory($fullFtpDir);
        ['uid' => $uid, 'gid' => $gid] = $this->ftpDriver->resolveUidGid($user->username);

        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        return DB::transaction(function () use ($user, $domain, $finalUsername, $passwordHash, $uid, $gid, $fullFtpDir, $data, $actor) {
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

            $this->auditLogger?->log([
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
        });
    }
}
