<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Contracts;

use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;

interface SslCertificateInspectorInterface
{
    /**
     * Inspect the configured panel TLS certificate for presence, trust, expiry, and hostname coverage.
     */
    public function inspect(string $expectedHost, ?string $certPath = null): SslCertificateInspectionResult;
}
