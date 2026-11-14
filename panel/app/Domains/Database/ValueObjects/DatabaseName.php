<?php

namespace App\Domains\Database\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class DatabaseName implements Stringable
{
    private const PROTECTED_DATABASES = [
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'litecontrol',
        'pdns',
    ];

    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid database name '{$value}'. Must be 1-64 alphanumeric or underscore characters.");
        }

        $this->value = $trimmed;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function isProtected(): bool
    {
        return in_array(strtolower($this->value), self::PROTECTED_DATABASES, true);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
