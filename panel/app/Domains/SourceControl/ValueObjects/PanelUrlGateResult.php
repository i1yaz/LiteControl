<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PanelUrlGateResult
{
    public const CODE_OK = 'ok';

    public const CODE_NOT_HTTPS = 'not_https';

    public const CODE_IP_OR_LOCAL_HOST = 'ip_or_local_host';

    public const CODE_HOST_MISMATCH = 'host_mismatch';

    public const CODE_CERT_MISSING = 'cert_missing';

    public const CODE_CERT_SELF_SIGNED = 'cert_self_signed';

    public const CODE_CERT_EXPIRED = 'cert_expired';

    public const CODE_CERT_HOST_MISMATCH = 'cert_host_mismatch';

    /** @var list<string> */
    public const VALID_CODES = [
        self::CODE_OK,
        self::CODE_NOT_HTTPS,
        self::CODE_IP_OR_LOCAL_HOST,
        self::CODE_HOST_MISMATCH,
        self::CODE_CERT_MISSING,
        self::CODE_CERT_SELF_SIGNED,
        self::CODE_CERT_EXPIRED,
        self::CODE_CERT_HOST_MISMATCH,
    ];

    public function __construct(
        public bool $allowed,
        public string $code,
        public string $message,
        public ?string $configuredUrl = null,
        public ?CarbonImmutable $certExpiresAt = null,
    ) {
        if (! in_array($code, self::VALID_CODES, true)) {
            throw new InvalidArgumentException("Invalid PanelUrlGateResult code [{$code}].");
        }

        if ($allowed && $code !== self::CODE_OK) {
            throw new InvalidArgumentException('Allowed PanelUrlGateResult must use code [ok].');
        }

        if (! $allowed && $code === self::CODE_OK) {
            throw new InvalidArgumentException('Denied PanelUrlGateResult cannot use code [ok].');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('PanelUrlGateResult message cannot be empty.');
        }
    }

    public static function allowed(string $configuredUrl, ?CarbonImmutable $certExpiresAt = null): self
    {
        return new self(
            allowed: true,
            code: self::CODE_OK,
            message: 'Panel URL and SSL certificate satisfy Source Control requirements.',
            configuredUrl: $configuredUrl,
            certExpiresAt: $certExpiresAt,
        );
    }

    public static function denied(
        string $code,
        string $message,
        ?string $configuredUrl = null,
        ?CarbonImmutable $certExpiresAt = null,
    ): self {
        return new self(
            allowed: false,
            code: $code,
            message: $message,
            configuredUrl: $configuredUrl,
            certExpiresAt: $certExpiresAt,
        );
    }

    public function expiresWithinDays(int $days, ?CarbonImmutable $now = null): bool
    {
        if ($this->certExpiresAt === null) {
            return false;
        }

        $reference = $now ?? CarbonImmutable::now();

        return $this->certExpiresAt->lessThanOrEqualTo($reference->addDays($days));
    }

    public function daysUntilExpiry(?CarbonImmutable $now = null): ?int
    {
        if ($this->certExpiresAt === null) {
            return null;
        }

        $reference = $now ?? CarbonImmutable::now();

        return (int) floor($reference->diffInSeconds($this->certExpiresAt, false) / 86400);
    }

    /**
     * @return array{allowed: bool, code: string, message: string, configured_url: ?string, cert_expires_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'message' => $this->message,
            'configured_url' => $this->configuredUrl,
            'cert_expires_at' => $this->certExpiresAt?->toIso8601String(),
        ];
    }
}
