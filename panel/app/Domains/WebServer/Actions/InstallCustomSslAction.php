<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Auth;

class InstallCustomSslAction
{
    public function __construct(
        protected SslDriverInterface $sslDriver,
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Validate and install custom SSL certificate and private key.
     *
     * @return array{status: string, message: string, domain?: Domain}
     */
    public function handle(Domain $domain, string $certificate, string $privateKey, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user() ?? $domain->user;

        if (function_exists('openssl_x509_read') && function_exists('openssl_pkey_get_private')) {
            $certRes = @openssl_x509_read($certificate);
            if (! $certRes && ! $this->sslDriver->isMockMode()) {
                return ['status' => 'error', 'message' => 'Provided certificate is not a valid X.509 PEM certificate.'];
            }

            $keyRes = @openssl_pkey_get_private($privateKey);
            if (! $keyRes && ! $this->sslDriver->isMockMode()) {
                return ['status' => 'error', 'message' => 'Provided private key is invalid or corrupted.'];
            }

            if ($certRes && $keyRes && ! @openssl_x509_check_private_key($certRes, $keyRes)) {
                return ['status' => 'error', 'message' => 'Certificate and private key do not match.'];
            }

            $certInfo = $certRes ? openssl_x509_parse($certRes) : null;
            $expiresAt = ! empty($certInfo['validTo_time_t'])
                ? now()->setTimestamp($certInfo['validTo_time_t'])
                : now()->addYear();
        } else {
            $expiresAt = now()->addYear();
        }

        $tempCert = tempnam(sys_get_temp_dir(), 'lc_cert_');
        $tempKey = tempnam(sys_get_temp_dir(), 'lc_key_');
        file_put_contents($tempCert, $certificate);
        file_put_contents($tempKey, $privateKey);

        try {
            $this->sslDriver->installCustom($domain->domain, $tempCert, $tempKey);
        } catch (LcCliException $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        } finally {
            @unlink($tempCert);
            @unlink($tempKey);
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => $expiresAt,
        ]);

        $this->auditLogger?->log([
            'user_id' => $actor->id,
            'action' => 'ssl_custom_install',
            'command' => "lc-cli ssl install-custom {$domain->domain}",
            'status' => 'success',
            'ip_address' => request()->ip(),
            'details' => ['domain' => $domain->domain, 'expires_at' => $expiresAt->toIso8601String()],
        ]);

        return [
            'status' => 'success',
            'message' => "Custom SSL certificate installed successfully for {$domain->domain}.",
            'domain' => $domain,
        ];
    }
}
