<?php

declare(strict_types=1);

namespace App\Domains\Identity\ValueObjects;

use InvalidArgumentException;

final readonly class PhpCliVersion
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (! preg_match('/^[5-9]\.[0-9]$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid PHP CLI version '{$value}'. Expected format X.Y (e.g. 8.3).");
        }

        $this->value = $trimmed;
    }

    public function toLsphpVersion(): string
    {
        return str_replace('.', '', $this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
