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

class SuspendDomainAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Suspend a domain virtualhost.
     *
     * @return array{status: string, message: string, domain: Domain}
     *
     * @throws RuntimeException
     */
    public function handle(Domain $domain, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        try {
            $this->vhostDriver->suspend($domain->domain);
        } catch (LcCliException $e) {
            $msg = $e->getMessage();
            Log::error("SuspendDomainAction: Suspend failed for {$domain->domain}: {$msg}");
            throw new RuntimeException($msg, previous: $e);
        }

        $domain->update(['status' => 'suspended']);

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'domain.suspend',
            'command' => "lc-cli vhost suspend {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        return [
            'status' => 'success',
            'message' => "Domain '{$domain->domain}' has been suspended.",
            'domain' => $domain,
        ];
    }
}
