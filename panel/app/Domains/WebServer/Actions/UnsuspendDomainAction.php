<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UnsuspendDomainAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Unsuspend a domain virtualhost.
     *
     * @return array{status: string, message: string, domain: Domain}
     *
     * @throws RuntimeException
     */
    public function handle(Domain $domain, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        try {
            $this->vhostDriver->unsuspend($domain->domain);
        } catch (LcCliException $e) {
            $msg = $e->getMessage();
            Log::error("UnsuspendDomainAction: Unsuspend failed for {$domain->domain}: {$msg}");
            throw new RuntimeException($msg, previous: $e);
        }

        $domain->update(['status' => 'active']);

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'domain.unsuspend',
            'command' => "lc-cli vhost unsuspend {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        return [
            'status' => 'success',
            'message' => "Domain '{$domain->domain}' has been activated.",
            'domain' => $domain,
        ];
    }
}
