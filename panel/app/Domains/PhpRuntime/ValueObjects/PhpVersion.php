<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\ValueObjects;

use InvalidArgumentException;

final readonly class PhpVersion
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (! preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid PHP version: '{$value}'.");
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
