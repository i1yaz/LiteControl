<?php

namespace App\Actions\Domain;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\System\VhostService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateSubdomainAction
{
    public function __construct(
        protected VhostService $vhostService
    ) {}

    /**
     * Create a subdomain virtual host and database record.
     *
     * @throws RuntimeException
     */
    public function handle(Domain $parentDomain, string $prefix, User $actor): Domain
    {
        $prefix = strtolower(trim($prefix));
        $subdomainFqdn = "{$prefix}.{$parentDomain->domain}";

        $owner = $parentDomain->user;
        $phpVersion = $parentDomain->php_version ?? '83';
        $docRoot = "/home/{$owner->username}/{$subdomainFqdn}/public_html";

        // Create virtual host via VhostService
        try {
            $this->vhostService->create(
                domain: $subdomainFqdn,
                username: $owner->username,
                phpVersion: $phpVersion,
                docRoot: $docRoot
            );
        } catch (\Throwable $e) {
            throw new RuntimeException("VirtualHost creation failed: {$e->getMessage()}", previous: $e);
        }

        return DB::transaction(function () use ($parentDomain, $owner, $subdomainFqdn, $phpVersion, $docRoot, $actor) {
            $domain = Domain::create([
                'user_id' => $parentDomain->user_id,
                'parent_domain_id' => $parentDomain->id,
                'domain' => $subdomainFqdn,
                'type' => 'subdomain',
                'php_version' => $phpVersion,
                'document_root' => $docRoot,
                'ssl_enabled' => false,
                'status' => 'active',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'subdomain.create',
                'command' => "lc-cli vhost add {$subdomainFqdn} {$owner->username} {$phpVersion} {$docRoot}",
                'status' => 'success',
                'details' => [
                    'domain' => $subdomainFqdn,
                    'parent_domain_id' => $parentDomain->id,
                    'user_id' => $owner->id,
                ],
            ]);

            return $domain;
        });
    }
}
