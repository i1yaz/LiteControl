<?php

declare(strict_types=1);

namespace App\Domains\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SslCertificateIssued
{
    use Dispatchable;

    public function __construct(
        public readonly string $domain,
        public readonly string $certPath,
        public readonly string $keyPath,
        public readonly int $expiresAt,
    ) {}
}
