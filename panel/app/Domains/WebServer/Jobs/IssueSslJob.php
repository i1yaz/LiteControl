<?php

declare(strict_types=1);

namespace App\Domains\WebServer\Jobs;

use App\Domains\Shared\Events\SslCertificateIssued;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IssueSslJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Domain $domain,
        public readonly string $email,
        public readonly bool $forceRenew = false,
    ) {
        $this->afterCommit();
    }

    public function handle(SslDriverInterface $sslDriver): void
    {
        $cacheKey = "ssl_issue_{$this->domain->id}";
        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));

        try {
            if ($this->forceRenew) {
                $result = $sslDriver->renew($this->domain->domain);
            } else {
                $result = $sslDriver->issue($this->domain->domain, $this->domain->document_root, $this->email);
            }

            $expiresAt = now()->addDays(90);

            $this->domain->update([
                'ssl_enabled' => true,
                'ssl_expires_at' => $expiresAt,
            ]);

            $certPath = is_string($result['cert_path'] ?? null)
                ? $result['cert_path']
                : "/etc/letsencrypt/live/{$this->domain->domain}/fullchain.pem";
            $keyPath = is_string($result['key_path'] ?? null)
                ? $result['key_path']
                : "/etc/letsencrypt/live/{$this->domain->domain}/privkey.pem";

            SslCertificateIssued::dispatch(
                $this->domain->domain,
                $certPath,
                $keyPath,
                $expiresAt->timestamp,
            );

            Cache::put($cacheKey, 'active', now()->addMinutes(30));
        } catch (\Throwable $e) {
            Cache::put($cacheKey, 'failed', now()->addMinutes(30));
            Log::warning("IssueSslJob: failed for {$this->domain->domain}: {$e->getMessage()}");
            throw $e;
        }
    }
}
