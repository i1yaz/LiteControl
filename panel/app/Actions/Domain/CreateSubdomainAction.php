<?php

namespace App\Actions\Domain;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\Cli\LcCliService;
use App\Services\System\VhostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreateSubdomainAction
{
    public ?string $lastWarning = null;

    public function __construct(
        protected VhostService $vhostService,
        protected ?LcCliService $lcCli = null
    ) {}

    /**
     * Create a subdomain virtual host and database record.
     *
     * @throws RuntimeException
     */
    public function handle(Domain $parentDomain, string $prefix, User $actor, array $options = []): Domain
    {
        $this->lastWarning = null;
        $prefix = strtolower(trim($prefix));
        $subdomainFqdn = "{$prefix}.{$parentDomain->domain}";

        $owner = $parentDomain->user;
        $phpVersion = $options['php_version'] ?? $parentDomain->php_version ?? '83';
        $publicSubpath = trim($options['public_path'] ?? '', " /\t\n\r\0\x0B");

        $baseDocRoot = "/home/{$owner->username}/{$subdomainFqdn}/public_html";
        $docRoot = ! empty($publicSubpath) ? "{$baseDocRoot}/{$publicSubpath}" : $baseDocRoot;

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

        $domain = DB::transaction(function () use ($parentDomain, $owner, $subdomainFqdn, $phpVersion, $docRoot, $publicSubpath, $actor) {
            $domain = Domain::create([
                'user_id' => $parentDomain->user_id,
                'parent_domain_id' => $parentDomain->id,
                'domain' => $subdomainFqdn,
                'type' => 'subdomain',
                'php_version' => $phpVersion,
                'document_root' => $docRoot,
                'public_path' => $publicSubpath ?: null,
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

        // Issue SSL certificate if requested
        $requestSsl = filter_var($options['ssl_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($requestSsl) {
            $lcCli = $this->lcCli ?? app(LcCliService::class);
            $sslRes = $lcCli->run('ssl', [
                'issue',
                $subdomainFqdn,
                $docRoot,
                $owner->email,
            ]);

            if (($sslRes['status'] ?? '') === 'success') {
                $domain->update([
                    'ssl_enabled' => true,
                    'ssl_expires_at' => now()->addDays(90),
                ]);
            } else {
                $this->lastWarning = "SSL certificate could not be issued automatically (DNS records may not have propagated yet). You can issue it later from the SSL page once DNS is pointed.";
                Log::warning("CreateSubdomainAction: SSL auto-issue failed for {$subdomainFqdn}: " . ($sslRes['message'] ?? 'Unknown error'));
            }
        }

        return $domain;
    }
}
