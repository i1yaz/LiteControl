<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeleteFtpAccountAction
{
    public function __construct(
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(FtpAccount $ftpAccount, ?User $actor = null): bool
    {
        $actor = $actor ?? Auth::user();
        $name = $ftpAccount->username;

        return DB::transaction(function () use ($ftpAccount, $actor, $name) {
            $ftpAccount->delete();

            $this->auditLogger?->log([
                'user_id' => $actor?->id ?? 1,
                'action' => 'ftp.delete',
                'command' => "ftp delete {$name}",
                'status' => 'success',
                'ip_address' => request()->ip(),
                'details' => ['username' => $name],
            ]);

            return true;
        });
    }
}
