<?php

namespace App\Domains\Security\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class Port implements Stringable
{
    public int $value;

    public function __construct(int $value)
    {
        if ($value < 1 || $value > 65535) {
            throw new InvalidArgumentException("Invalid port number {$value}. Must be between 1 and 65535.");
        }

        $this->value = $value;
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
