<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class SslCertificateInspectionResult
{
    /**
     * @param  list<string>  $dnsNames
     */
    public function __construct(
        public bool $present,
        public bool $selfSigned = false,
        public bool $trusted = false,
        public ?CarbonImmutable $validFrom = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $dnsNames = [],
        public ?string $subject = null,
        public ?string $issuer = null,
        public ?string $error = null,
    ) {}

    public static function missing(?string $error = null): self
    {
        return new self(
            present: false,
            error: $error,
        );
    }

    /**
     * @param  list<string>  $dnsNames
     */
    public static function found(
        bool $selfSigned,
        bool $trusted,
        ?CarbonImmutable $validFrom,
        ?CarbonImmutable $expiresAt,
        array $dnsNames,
        ?string $subject = null,
        ?string $issuer = null,
        ?string $error = null,
    ): self {
        return new self(
            present: true,
            selfSigned: $selfSigned,
            trusted: $trusted,
            validFrom: $validFrom,
            expiresAt: $expiresAt,
            dnsNames: array_values($dnsNames),
            subject: $subject,
            issuer: $issuer,
            error: $error,
        );
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        if (! $this->present || $this->expiresAt === null) {
            return true;
        }

        $reference = $now ?? CarbonImmutable::now();

        if ($this->validFrom !== null && $reference->lessThan($this->validFrom)) {
            return true;
        }

        return $this->expiresAt->lessThanOrEqualTo($reference);
    }

    public function matchesHost(string $host): bool
    {
        $normalizedHost = strtolower(rtrim(trim($host), '.'));
        if ($normalizedHost === '' || $this->dnsNames === []) {
            return false;
        }

        foreach ($this->dnsNames as $pattern) {
            $normalizedPattern = strtolower(rtrim(trim($pattern), '.'));
            if ($normalizedPattern === '') {
                continue;
            }

            if ($normalizedPattern === $normalizedHost) {
                return true;
            }

            // RFC 6125 single-label wildcard match (e.g. *.example.com matches panel.example.com)
            if (str_starts_with($normalizedPattern, '*.')) {
                $suffix = substr($normalizedPattern, 2);
                if ($suffix !== '' && str_contains($suffix, '.') && str_ends_with($normalizedHost, '.'.$suffix)) {
                    $prefix = substr($normalizedHost, 0, -(strlen($suffix) + 1));
                    if ($prefix !== '' && ! str_contains($prefix, '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
