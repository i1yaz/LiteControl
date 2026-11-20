<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\FtpDriverInterface;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateFtpAccountAction
{
    public function __construct(
        protected FtpDriverInterface $ftpDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Update an existing FTP account's home directory and disk quota.
     */
    public function handle(FtpAccount $ftpAccount, array $data, ?User $actor = null): FtpAccount
    {
        $actor = $actor ?? Auth::user();
        $domain = $ftpAccount->domain;
        $owner = $domain->user;

        $rawSubdir = trim((string) ($data['dir'] ?? ''), ' /\\');
        $domainBase = "/home/{$owner->username}/{$domain->domain}";
        $fullFtpDir = $rawSubdir === '' ? "{$domainBase}/public_html" : "{$domainBase}/public_html/{$rawSubdir}";

        $this->ftpDriver->ensureDirectory($fullFtpDir);

        $quotaMb = (int) ($data['quota_mb'] ?? 0);

        return DB::transaction(function () use ($ftpAccount, $fullFtpDir, $quotaMb, $actor) {
            $ftpAccount->update([
                'dir' => $fullFtpDir,
                'quota_mb' => $quotaMb,
            ]);

            $this->auditLogger?->log([
                'user_id' => $actor?->id ?? 1,
                'action' => 'ftp.update',
                'command' => "ftp update {$ftpAccount->username}",
                'status' => 'success',
                'ip_address' => request()->ip(),
                'details' => [
                    'username' => $ftpAccount->username,
                    'dir' => $fullFtpDir,
                    'quota_mb' => $quotaMb,
                ],
            ]);

            return $ftpAccount->fresh();
        });
    }
}
