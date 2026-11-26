<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use InvalidArgumentException;

final readonly class GitHubOrgName
{
    public function __construct(
        public string $value,
    ) {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException("Invalid GitHub organization name [{$value}].");
        }
    }

    public static function fromNullable(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return new self(trim($value));
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[a-zA-Z0-9-]{1,39}$/', $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
