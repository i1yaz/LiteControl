<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DomainService
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Centralized domain provisioning.
     *
     * @param User $user The owner of the domain
     * @param array $data Input data: domain, php_version, public_path, ssl_enabled, type
     * @param User|null $actor The user triggering the creation (for audit logs)
     * @return array ['domain' => Domain, 'ssl_issued' => bool, 'warning' => ?string]
     * @throws \RuntimeException If virtual host creation fails
     */
    public function createDomain(User $user, array $data, ?User $actor = null): array
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

        // 1. Provision virtual host via lc-cli
        $cliRes = $this->lcCli->run('vhost', [
            'add',
            $domainName,
            $user->username,
            $phpVersion,
            $docRoot,
        ]);

        if (($cliRes['status'] ?? '') !== 'success') {
            $msg = $cliRes['message'] ?? 'Failed to create virtual host.';
            Log::error("DomainService: vhost add failed for {$domainName}: {$msg}");
            throw new \RuntimeException($msg);
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
        $sslWarning = null;

        // 3. Issue SSL certificate if requested (default ON)
        $requestSsl = filter_var($data['ssl_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($requestSsl) {
            $sslRes = $this->lcCli->run('ssl', [
                'issue',
                $domainName,
                $docRoot,
                $user->email,
            ]);

            if (($sslRes['status'] ?? '') === 'success') {
                $domain->update([
                    'ssl_enabled' => true,
                    'ssl_expires_at' => now()->addDays(90),
                ]);
                $sslIssued = true;
            } else {
                $sslWarning = "SSL certificate could not be issued automatically (DNS records may not have propagated yet). You can issue it later from the SSL page once DNS is pointed.";
                Log::warning("DomainService: SSL auto-issue failed for {$domainName}: " . ($sslRes['message'] ?? 'Unknown error'));
            }
        }

        // 4. Audit Log
        AuditLog::create([
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
            ],
        ]);

        return [
            'domain' => $domain,
            'ssl_issued' => $sslIssued,
            'warning' => $sslWarning,
        ];
    }

    /**
     * Update an existing domain configuration.
     *
     * @param Domain $domain
     * @param array $data Input: php_version, public_path, ssl_enabled
     * @param User|null $actor
     * @return array ['domain' => Domain, 'ssl_issued' => bool, 'warning' => ?string]
     */
    public function updateDomain(Domain $domain, array $data, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        $sslIssued = $domain->ssl_enabled;
        $sslWarning = null;

        // 1. PHP Version switch
        if (!empty($data['php_version']) && $data['php_version'] !== $domain->php_version) {
            $this->switchPhpVersion($domain, $data['php_version'], $actor);
        }

        // 2. Pointing Location / Document Root switch
        if (array_key_exists('public_path', $data)) {
            $newSubpath = trim($data['public_path'] ?? '', " /\t\n\r\0\x0B");
            $baseDocRoot = "/home/{$user->username}/{$domain->domain}/public_html";
            $newDocRoot = !empty($newSubpath) ? "{$baseDocRoot}/{$newSubpath}" : $baseDocRoot;

            if ($newDocRoot !== $domain->document_root) {
                $docRes = $this->lcCli->run('vhost', ['docroot', $domain->domain, $newDocRoot]);
                if (($docRes['status'] ?? '') === 'success') {
                    $domain->document_root = $newDocRoot;
                    $domain->public_path = $newSubpath ?: null;
                } else {
                    throw new \RuntimeException($docRes['message'] ?? 'Failed to update document root.');
                }
            }
        }

        // 3. SSL toggle / issue
        if (isset($data['ssl_enabled'])) {
            $wantSsl = filter_var($data['ssl_enabled'], FILTER_VALIDATE_BOOLEAN);
            if ($wantSsl && ! $domain->ssl_enabled) {
                $sslRes = $this->lcCli->run('ssl', [
                    'issue',
                    $domain->domain,
                    $domain->document_root,
                    $user->email,
                ]);

                if (($sslRes['status'] ?? '') === 'success') {
                    $domain->ssl_enabled = true;
                    $domain->ssl_expires_at = now()->addDays(90);
                    $sslIssued = true;
                } else {
                    $sslWarning = "SSL issuance failed: " . ($sslRes['message'] ?? 'DNS check failed');
                }
            }
        }

        $domain->save();

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'domain.update',
            'command' => "lc-cli vhost update {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => [
                'domain' => $domain->domain,
                'php_version' => $domain->php_version,
                'doc_root' => $domain->document_root,
            ],
        ]);

        return [
            'domain' => $domain,
            'ssl_issued' => $sslIssued,
            'warning' => $sslWarning,
        ];
    }

    /**
     * Centralized, reusable PHP version switching method.
     */
    public function switchPhpVersion(Domain $domain, string $phpVersion, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        $res = $this->lcCli->run('vhost', ['php-switch', $domain->domain, $phpVersion]);

        if (($res['status'] ?? '') !== 'success') {
            $msg = $res['message'] ?? 'Failed to switch PHP version.';
            Log::error("DomainService: PHP switch failed for {$domain->domain} to {$phpVersion}: {$msg}");
            throw new \RuntimeException($msg);
        }

        $oldVersion = $domain->php_version;
        $domain->update(['php_version' => $phpVersion]);

        AuditLog::create([
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

    /**
     * Suspend a domain virtualhost.
     */
    public function suspendDomain(Domain $domain, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        $res = $this->lcCli->run('vhost', ['suspend', $domain->domain]);

        if (($res['status'] ?? '') !== 'success') {
            $msg = $res['message'] ?? 'Failed to suspend domain virtual host.';
            Log::error("DomainService: Suspend failed for {$domain->domain}: {$msg}");
            throw new \RuntimeException($msg);
        }

        $domain->update(['status' => 'suspended']);

        AuditLog::create([
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

    /**
     * Unsuspend a domain virtualhost.
     */
    public function unsuspendDomain(Domain $domain, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        $res = $this->lcCli->run('vhost', ['unsuspend', $domain->domain]);

        if (($res['status'] ?? '') !== 'success') {
            $msg = $res['message'] ?? 'Failed to unsuspend domain virtual host.';
            Log::error("DomainService: Unsuspend failed for {$domain->domain}: {$msg}");
            throw new \RuntimeException($msg);
        }

        $domain->update(['status' => 'active']);

        AuditLog::create([
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

    /**
     * Register a domain alias for a primary/addon domain.
     */
    public function addAlias(Domain $parentDomain, string $aliasFqdn, ?User $actor = null): Domain
    {
        $actor = $actor ?? Auth::user() ?? $parentDomain->user;
        $user = $parentDomain->user;
        $aliasFqdn = strtolower(trim($aliasFqdn));

        if (Domain::where('domain', $aliasFqdn)->exists()) {
            throw new \RuntimeException("Domain '{$aliasFqdn}' is already registered.");
        }

        $res = $this->lcCli->run('vhost', ['alias-add', $parentDomain->domain, $aliasFqdn]);

        if (($res['status'] ?? '') !== 'success') {
            throw new \RuntimeException($res['message'] ?? 'Failed to add alias virtual host.');
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

        AuditLog::create([
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

    /**
     * Batch switch PHP version for multiple domains.
     */
    public function batchSwitchPhpVersion(iterable $domains, string $phpVersion, ?User $actor = null): int
    {
        $count = 0;
        foreach ($domains as $domain) {
            try {
                $this->switchPhpVersion($domain, $phpVersion, $actor);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("Batch PHP switch skipped for {$domain->domain}: {$e->getMessage()}");
            }
        }
        return $count;
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
        $suffix = '.' . $newDomain->domain;
        $children = Domain::with('parentDomain')
            ->where('user_id', $newDomain->user_id)
            ->where('id', '!=', $newDomain->id)
            ->where('type', '!=', 'alias')
            ->where('domain', 'like', '%' . $suffix)
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

