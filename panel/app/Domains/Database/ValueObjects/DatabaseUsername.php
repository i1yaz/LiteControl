<?php

namespace App\Domains\Database\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class DatabaseUsername implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! preg_match('/^[a-zA-Z0-9_]{1,32}$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid database username '{$value}'. Must be 1-32 alphanumeric or underscore characters.");
        }

        $this->value = $trimmed;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

