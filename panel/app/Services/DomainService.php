<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
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

        // Base directory is always /home/<username>/<domain>/public_html
        $baseDocRoot = "/home/{$user->username}/{$domainName}/public_html";
        $docRoot = !empty($publicSubpath) ? "{$baseDocRoot}/{$publicSubpath}" : $baseDocRoot;

        // Auto-assign type: if user has 0 non-subdomain domains, this is their primary domain
        $existingCount = Domain::where('user_id', $user->id)
            ->where('type', '!=', 'subdomain')
            ->count();
        $type = $data['type'] ?? ($existingCount === 0 ? 'primary' : 'addon');

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
            'domain' => $domainName,
            'type' => $type,
            'document_root' => $docRoot,
            'public_path' => $publicSubpath ?: null,
            'php_version' => $phpVersion,
            'ssl_enabled' => false,
            'status' => 'active',
        ]);

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
            $phpRes = $this->lcCli->run('vhost', ['php-switch', $domain->domain, $data['php_version']]);
            if (($phpRes['status'] ?? '') === 'success') {
                $domain->php_version = $data['php_version'];
            } else {
                throw new \RuntimeException($phpRes['message'] ?? 'Failed to update PHP version.');
            }
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
}

