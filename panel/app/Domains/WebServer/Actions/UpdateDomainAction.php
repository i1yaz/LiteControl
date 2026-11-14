<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class UpdateDomainAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected SslDriverInterface $sslDriver,
        protected SwitchDomainPhpVersionAction $switchDomainPhpVersion,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Update an existing domain configuration.
     *
     * @param  array  $data  Input: php_version, public_path, ssl_enabled
     * @return array{domain: Domain, ssl_issued: bool, warning: ?string}
     *
     * @throws RuntimeException
     */
    public function handle(Domain $domain, array $data, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        $sslIssued = $domain->ssl_enabled;
        $sslWarning = null;

        // 1. PHP Version switch
        if (! empty($data['php_version']) && $data['php_version'] !== $domain->php_version) {
            $this->switchDomainPhpVersion->handle($domain, $data['php_version'], $actor);
        }

        // 2. Pointing Location / Document Root switch
        if (array_key_exists('public_path', $data)) {
            $newSubpath = trim($data['public_path'] ?? '', " /\t\n\r\0\x0B");
            $baseDocRoot = "/home/{$user->username}/{$domain->domain}/public_html";
            $newDocRoot = ! empty($newSubpath) ? "{$baseDocRoot}/{$newSubpath}" : $baseDocRoot;

            if ($newDocRoot !== $domain->document_root) {
                try {
                    $this->vhostDriver->setDocRoot($domain->domain, $newDocRoot);
                    $domain->document_root = $newDocRoot;
                    $domain->public_path = $newSubpath ?: null;
                } catch (LcCliException $e) {
                    throw new RuntimeException($e->getMessage(), previous: $e);
                }
            }
        }

        // 3. SSL toggle / issue
        if (isset($data['ssl_enabled'])) {
            $wantSsl = filter_var($data['ssl_enabled'], FILTER_VALIDATE_BOOLEAN);
            if ($wantSsl && ! $domain->ssl_enabled) {
                try {
                    $this->sslDriver->issue($domain->domain, $domain->document_root, $user->email ?? '');
                    $domain->ssl_enabled = true;
                    $domain->ssl_expires_at = now()->addDays(90);
                    $sslIssued = true;
                } catch (LcCliException $e) {
                    $sslWarning = 'SSL issuance failed: '.$e->getMessage();
                }
            }
        }

        $domain->save();

        $this->auditLogger?->log([
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
