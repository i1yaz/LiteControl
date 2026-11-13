<?php

declare(strict_types=1);

namespace App\Domains\Backup\ValueObjects;

use InvalidArgumentException;

final readonly class BackupPath
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, '..')) {
            throw new InvalidArgumentException("Invalid backup path: '{$value}'.");
        }

        if (! str_starts_with($trimmed, '/')) {
            throw new InvalidArgumentException("Backup path must be absolute: '{$value}'.");
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

