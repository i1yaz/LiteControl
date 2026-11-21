<?php

declare(strict_types=1);

namespace App\Domains\Mail\ValueObjects;

use InvalidArgumentException;

final readonly class EmailAddress
{
    private const LOCAL_PART_REGEX = '/^[a-z0-9][a-z0-9._+-]{0,63}$/';

    private const DOMAIN_REGEX = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    private const FORBIDDEN_LOCAL_PARTS = [
        'mailer-daemon',
    ];

    public string $localPart;

    public string $domain;

    public string $value;

    public function __construct(string $email)
    {
        $normalized = strtolower(trim($email));

        if (str_contains($normalized, '/') || str_contains($normalized, '\\') || str_contains($normalized, "\0")) {
            throw new InvalidArgumentException("Invalid email address: '{$email}'.");
        }

        $parts = explode('@', $normalized);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid email address: '{$email}'.");
        }

        [$localPart, $domain] = $parts;

        self::assertValidLocalPart($localPart);
        self::assertValidDomain($domain);

        $this->localPart = $localPart;
        $this->domain = $domain;
        $this->value = "{$localPart}@{$domain}";
    }

    public static function fromParts(string $localPart, string $domain): self
    {
        return new self(trim($localPart).'@'.trim($domain));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function assertValidLocalPart(string $localPart): void
    {
        if (
            $localPart === ''
            || ! preg_match(self::LOCAL_PART_REGEX, $localPart)
            || str_contains($localPart, '..')
            || str_ends_with($localPart, '.')
            || in_array($localPart, self::FORBIDDEN_LOCAL_PARTS, true)
        ) {
            throw new InvalidArgumentException("Invalid email local part: '{$localPart}'.");
        }
    }

    private static function assertValidDomain(string $domain): void
    {
        if ($domain === '' || strlen($domain) > 255 || ! preg_match(self::DOMAIN_REGEX, $domain)) {
            throw new InvalidArgumentException("Invalid email domain: '{$domain}'.");
        }
    }
}
