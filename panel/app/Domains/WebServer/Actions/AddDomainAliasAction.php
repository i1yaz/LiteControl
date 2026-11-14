<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class AddDomainAliasAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Register a domain alias for a primary/addon domain.
     *
     * @throws RuntimeException
     */
    public function handle(Domain $parentDomain, string $aliasFqdn, ?User $actor = null): Domain
    {
        $actor = $actor ?? Auth::user() ?? $parentDomain->user;
        $user = $parentDomain->user;
        $aliasFqdn = strtolower(trim($aliasFqdn));

        if (Domain::where('domain', $aliasFqdn)->exists()) {
            throw new RuntimeException("Domain '{$aliasFqdn}' is already registered.");
        }

        try {
            $this->vhostDriver->addAlias($parentDomain->domain, $aliasFqdn);
        } catch (LcCliException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        $aliasDomain = Domain::create([
            'user_id' => $user->id,
            'domain' => $aliasFqdn,
            'type' => 'alias',
            'parent_domain_id' => $parentDomain->id,
            'document_root' => $parentDomain->document_root,
            'public_path' => $parentDomain->public_path,
            'php_version' => $parentDomain->php_version,
            'ssl_enabled' => false,
            'status' => 'active',
        ]);

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'domain.alias_add',
            'command' => "lc-cli vhost alias-add {$parentDomain->domain} {$aliasFqdn}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => [
                'parent' => $parentDomain->domain,
                'alias' => $aliasFqdn,
            ],
        ]);

        return $aliasDomain;
    }
}
