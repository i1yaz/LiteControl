<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class GitHubAppName
{
    public const MAX_LENGTH = 34;

    private const PREFIX = 'lc-';

    private const RANDOM_SUFFIX_LENGTH = 6;

    public function __construct(
        public string $value,
    ) {
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'GitHub App name must be between 1 and '.self::MAX_LENGTH." characters; got [{$value}]."
            );
        }
    }

    public static function fromHost(string $host, ?string $randomSuffix = null): self
    {
        $sanitizedHost = strtolower(trim($host));
        $sanitizedHost = (string) preg_replace('/[^a-z0-9-]+/', '-', $sanitizedHost);
        $sanitizedHost = trim($sanitizedHost, '-');

        if ($sanitizedHost === '') {
            $sanitizedHost = 'panel';
        }

        $suffix = strtolower($randomSuffix ?? Str::lower(Str::random(self::RANDOM_SUFFIX_LENGTH)));
        $suffix = (string) preg_replace('/[^a-z0-9]/', '', $suffix);
        if (strlen($suffix) < self::RANDOM_SUFFIX_LENGTH) {
            $suffix = str_pad($suffix, self::RANDOM_SUFFIX_LENGTH, '0');
        }
        $suffix = substr($suffix, 0, self::RANDOM_SUFFIX_LENGTH);

        // Total budget: 34 = strlen('lc-') [3] + host + strlen('-') [1] + suffix [6] => max host = 24
        $maxHostLength = self::MAX_LENGTH - strlen(self::PREFIX) - 1 - self::RANDOM_SUFFIX_LENGTH;
        $truncatedHost = rtrim(substr($sanitizedHost, 0, $maxHostLength), '-');

        if ($truncatedHost === '') {
            $truncatedHost = 'panel';
        }

        return new self(self::PREFIX.$truncatedHost.'-'.$suffix);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
