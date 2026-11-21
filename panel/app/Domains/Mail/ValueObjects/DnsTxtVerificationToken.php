<?php

declare(strict_types=1);

namespace App\Domains\Mail\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class DnsTxtVerificationToken implements Stringable
{
    public const RECORD_PREFIX = '_lc-mail-verify';

    public const VALUE_PREFIX = 'lc-mail-verify=';

    public string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if (! preg_match('/^lc-mail-verify=[a-f0-9]{32}$/', $normalized)) {
            throw new InvalidArgumentException("Invalid DNS TXT verification token '{$value}'.");
        }

        $this->value = $normalized;
    }

    public static function forUserAndDomain(int $userId, string $domain, string $secret): self
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be positive.');
        }

        $normalizedDomain = strtolower(trim($domain));
        if ($normalizedDomain === '') {
            throw new InvalidArgumentException('Domain cannot be empty.');
        }

        if ($secret === '') {
            throw new InvalidArgumentException('Verification secret cannot be empty.');
        }

        $hash = substr(
            hash_hmac('sha256', "mail-domain-verify:{$userId}:{$normalizedDomain}", $secret),
            0,
            32
        );

        return new self(self::VALUE_PREFIX.$hash);
    }

    public static function recordName(string $domain): string
    {
        $normalizedDomain = strtolower(trim($domain));
        if ($normalizedDomain === '') {
            throw new InvalidArgumentException('Domain cannot be empty.');
        }

        return self::RECORD_PREFIX.'.'.$normalizedDomain;
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, trim($candidate));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
