<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Jobs\IssueSslJob;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreateDomainAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected SslDriverInterface $sslDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Centralized domain provisioning.
     *
     * @param  User  $user  The owner of the domain
     * @param  array  $data  Input data: domain, php_version, public_path, ssl_enabled, type
     * @param  User|null  $actor  The user triggering the creation (for audit logs)
     * @return array{domain: Domain, ssl_issued: bool, warning: ?string}
     *
     * @throws RuntimeException If virtual host creation fails
     */
    public function handle(User $user, array $data, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $user;

        $domainName = strtolower(trim($data['domain']));
        $phpVersion = $data['php_version'] ?? '83';
        $publicSubpath = trim($data['public_path'] ?? '', " /\t\n\r\0\x0B");

        $parentDomain = ! empty($data['parent_domain_id'])
            ? Domain::find($data['parent_domain_id'])
            : $this->findClosestParentDomain($user->id, $domainName);

        // Base directory is /home/<username>/<domain>/public_html
        $baseDocRoot = "/home/{$user->username}/{$domainName}/public_html";
        $docRoot = ! empty($publicSubpath) ? "{$baseDocRoot}/{$publicSubpath}" : $baseDocRoot;

        // Auto-assign type: subdomain if parent exists, otherwise primary (or explicit type)
        $parentDomainId = $parentDomain?->id;
        $type = $parentDomain ? 'subdomain' : ($data['type'] ?? 'primary');

        // 1. Provision virtual host via VhostDriverInterface
        try {
            $this->vhostDriver->create($domainName, $user->username, $phpVersion, $docRoot);
        } catch (LcCliException $e) {
            $msg = $e->getMessage();
            Log::error("CreateDomainAction: vhost add failed for {$domainName}: {$msg}");
            throw new RuntimeException($msg, previous: $e);
        }

        // 2. Persist domain record in database
        $domain = Domain::create([
            'user_id' => $user->id,
            'parent_domain_id' => $parentDomainId,
            'domain' => $domainName,
            'type' => $type,
            'document_root' => $docRoot,
            'public_path' => $publicSubpath ?: null,
            'php_version' => $phpVersion,
            'ssl_enabled' => false,
            'open_basedir_enabled' => false,
            'status' => 'active',
        ]);

        // Re-link any existing standalone/higher-level subdomains that belong under this new domain
        $this->relinkExistingChildren($domain);

        $sslIssued = false;
        $sslQueued = false;
        $sslWarning = null;

        // 3. Queue SSL certificate issuance asynchronously if requested (default ON)
        //    so website provisioning returns immediately without blocking on ACME HTTP-01 validation.
        $requestSsl = filter_var($data['ssl_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($requestSsl) {
            try {
                Cache::put("ssl_issue_{$domain->id}", 'pending', now()->addMinutes(30));
                IssueSslJob::dispatch($domain, $user->email ?? '');
                $sslQueued = true;
                $sslIssued = (bool) ($domain->fresh()?->ssl_enabled ?? false);
            } catch (\Throwable $e) {
                $sslWarning = 'SSL certificate could not be issued automatically (DNS records may not have propagated yet). You can issue it later from the SSL page once DNS is pointed.';
                Log::warning("CreateDomainAction: SSL auto-issue failed for {$domainName}: {$e->getMessage()}");
            }
        }

        // 4. Audit Log
        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'domain.create',
            'command' => "lc-cli vhost add {$domainName} {$user->username} {$phpVersion}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => [
                'domain' => $domainName,
                'owner' => $user->username,
                'php_version' => $phpVersion,
                'doc_root' => $docRoot,
                'ssl_issued' => $sslIssued,
                'ssl_queued' => $sslQueued,
            ],
        ]);

        return [
            'domain' => $domain->fresh() ?? $domain,
            'ssl_issued' => $sslIssued,
            'ssl_queued' => $sslQueued,
            'warning' => $sslWarning,
        ];
    }

    /**
     * Find the closest existing parent domain for a given FQDN (e.g. v1.api.example.com -> api.example.com -> example.com).
     */
    public function findClosestParentDomain(int $userId, string $domainName): ?Domain
    {
        $parts = explode('.', strtolower(trim($domainName)));
        if (count($parts) <= 2) {
            return null;
        }

        $candidates = [];
        for ($i = 1; $i <= count($parts) - 2; $i++) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }

        $matches = Domain::where('user_id', $userId)
            ->whereIn('domain', $candidates)
            ->get()
            ->keyBy('domain');

        foreach ($candidates as $candidate) {
            if ($matches->has($candidate)) {
                return $matches->get($candidate);
            }
        }

        return null;
    }

    /**
     * Re-link any existing domains owned by the same user that belong under this newly created domain.
     */
    public function relinkExistingChildren(Domain $newDomain): void
    {
        $suffix = '.'.$newDomain->domain;
        $children = Domain::with('parentDomain')
            ->where('user_id', $newDomain->user_id)
            ->where('id', '!=', $newDomain->id)
            ->where('type', '!=', 'alias')
            ->where('domain', 'like', '%'.$suffix)
            ->get();

        foreach ($children as $child) {
            $currentParentLen = $child->parentDomain ? strlen($child->parentDomain->domain) : 0;
            if ($child->parent_domain_id === null || strlen($newDomain->domain) > $currentParentLen) {
                $child->update([
                    'type' => 'subdomain',
                    'parent_domain_id' => $newDomain->id,
                ]);
            }
        }
    }
}
