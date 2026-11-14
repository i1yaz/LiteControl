<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Jobs\IssueSslJob;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class IssueSslAction
{
    public function __construct(
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Issue or renew Let's Encrypt SSL certificate for a domain.
     *
     * @return array{status: string, message: string, domain?: Domain}
     */
    public function handle(Domain $domain, ?User $actor = null, bool $forceRenew = false): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;
        $action = $forceRenew ? 'renew' : 'issue';

        Cache::put("ssl_issue_{$domain->id}", 'pending', now()->addMinutes(30));

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => $action === 'renew' ? 'ssl.renew' : 'ssl_issue',
            'command' => "lc-cli ssl {$action} {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        try {
            IssueSslJob::dispatch($domain, $user->email ?? '', $forceRenew);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'message' => $action === 'renew'
                ? "SSL certificate renewed successfully for {$domain->domain}."
                : "Let's Encrypt SSL certificate issued successfully for {$domain->domain}.",
            'domain' => $domain->fresh() ?? $domain,
        ];
    }
}
