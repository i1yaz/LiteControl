<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use InvalidArgumentException;

final readonly class SafeReturnPath
{
    public const DEFAULT_PATH = '/settings/source-control';

    public function __construct(
        public string $value,
    ) {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException("Invalid return path [{$value}]. Only relative same-origin paths starting with a single '/' are allowed.");
        }
    }

    public static function fromNullable(?string $value, string $default = self::DEFAULT_PATH): self
    {
        if ($value === null || trim($value) === '') {
            return new self($default);
        }

        return new self(trim($value));
    }

    public static function isValid(string $value): bool
    {
        if ($value === '' || strlen($value) > 2048) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            return false;
        }

        return preg_match('/^\/(?!\/)/', $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
