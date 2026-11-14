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

class SwitchDomainPhpVersionAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Switch PHP version for a domain virtualhost.
     *
     * @return array{status: string, domain: Domain, php_version: string}
     *
     * @throws RuntimeException
     */
    public function handle(Domain $domain, string $phpVersion, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        try {
            $this->vhostDriver->switchPhp($domain->domain, $phpVersion);
        } catch (LcCliException $e) {
            $msg = $e->getMessage();
            Log::error("SwitchDomainPhpVersionAction: PHP switch failed for {$domain->domain} to {$phpVersion}: {$msg}");
            throw new RuntimeException($msg, previous: $e);
        }

        $oldVersion = $domain->php_version;
        $domain->update(['php_version' => $phpVersion]);

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'domain.php_switch',
            'command' => "lc-cli vhost php-switch {$domain->domain} {$phpVersion}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => [
                'domain' => $domain->domain,
                'old_version' => $oldVersion,
                'new_version' => $phpVersion,
            ],
        ]);

        return [
            'status' => 'success',
            'domain' => $domain,
            'php_version' => $phpVersion,
        ];
    }
}
