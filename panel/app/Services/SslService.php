<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SslService
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Issue or renew Let's Encrypt SSL certificate for a domain.
     *
     * @param Domain $domain
     * @param User|null $actor
     * @return array ['status' => 'success|error', 'message' => string]
     */
    public function issueOrRenew(Domain $domain, ?User $actor = null, bool $forceRenew = false): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;
        $user = $domain->user;

        // Run renew if explicitly requested or if renew action, else issue
        $action = $forceRenew ? 'renew' : 'issue';
        $args = $action === 'renew'
            ? ['renew', $domain->domain]
            : ['issue', $domain->domain, $domain->document_root, $user->email ?? ''];

        $res = $this->lcCli->run('ssl', $args);

        if (($res['status'] ?? '') === 'error') {
            $msg = $res['message'] ?? 'Failed to issue/renew SSL certificate.';
            Log::warning("SslService: {$action} failed for {$domain->domain}: {$msg}");
            return [
                'status' => 'error',
                'message' => $msg,
            ];
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addDays(90),
        ]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action === 'renew' ? 'ssl.renew' : 'ssl_issue',
            'command' => "lc-cli ssl {$action} {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        return [
            'status' => 'success',
            'message' => $action === 'renew'
                ? "SSL certificate renewed successfully for {$domain->domain}."
                : "Let's Encrypt SSL certificate issued successfully for {$domain->domain}.",
            'domain' => $domain,
        ];
    }

    /**
     * Validate and install custom SSL certificate and private key.
     *
     * @param Domain $domain
     * @param string $certificate
     * @param string $privateKey
     * @param User|null $actor
     * @return array ['status' => 'success|error', 'message' => string]
     */
    public function installCustomSsl(Domain $domain, string $certificate, string $privateKey, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        // 1. In non-mock mode or whenever openssl extension is available, validate pair
        if (function_exists('openssl_x509_read') && function_exists('openssl_pkey_get_private')) {
            $certRes = @openssl_x509_read($certificate);
            if (! $certRes && ! $this->lcCli->isMockMode()) {
                return ['status' => 'error', 'message' => 'Provided certificate is not a valid X.509 PEM certificate.'];
            }

            $keyRes = @openssl_pkey_get_private($privateKey);
            if (! $keyRes && ! $this->lcCli->isMockMode()) {
                return ['status' => 'error', 'message' => 'Provided private key is invalid or corrupted.'];
            }

            if ($certRes && $keyRes && ! @openssl_x509_check_private_key($certRes, $keyRes)) {
                return ['status' => 'error', 'message' => 'Certificate and private key do not match.'];
            }

            $certInfo = $certRes ? openssl_x509_parse($certRes) : null;
            $expiresAt = !empty($certInfo['validTo_time_t'])
                ? now()->setTimestamp($certInfo['validTo_time_t'])
                : now()->addYear();
        } else {
            $expiresAt = now()->addYear();
        }

        // 2. Write temp files to hand securely to lc-cli
        $tempCert = tempnam(sys_get_temp_dir(), 'lc_cert_');
        $tempKey = tempnam(sys_get_temp_dir(), 'lc_key_');
        file_put_contents($tempCert, $certificate);
        file_put_contents($tempKey, $privateKey);

        $res = $this->lcCli->run('ssl', ['custom', $domain->domain, $tempCert, $tempKey]);

        @unlink($tempCert);
        @unlink($tempKey);

        if (($res['status'] ?? '') === 'error') {
            return [
                'status' => 'error',
                'message' => $res['message'] ?? 'Failed to install custom SSL certificate.',
            ];
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => $expiresAt,
        ]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'ssl.custom',
            'command' => "lc-cli ssl custom {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        return [
            'status' => 'success',
            'message' => "Custom SSL certificate installed successfully for {$domain->domain}.",
            'domain' => $domain,
        ];
    }
}
