<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateFtpPasswordAction
{
    public function __construct(
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Update an existing FTP account's password hash.
     */
    public function handle(FtpAccount $ftpAccount, string $password, ?User $actor = null): FtpAccount
    {
        $actor = $actor ?? Auth::user();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        return DB::transaction(function () use ($ftpAccount, $passwordHash, $actor) {
            $ftpAccount->update([
                'password_hash' => $passwordHash,
            ]);

            $this->auditLogger?->log([
                'user_id' => $actor?->id ?? 1,
                'action' => 'ftp.password',
                'command' => "ftp password {$ftpAccount->username}",
                'status' => 'success',
                'ip_address' => request()->ip(),
                'details' => [
                    'username' => $ftpAccount->username,
                ],
            ]);

            return $ftpAccount->fresh();
        });
    }
}
