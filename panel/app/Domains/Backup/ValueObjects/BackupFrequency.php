<?php

declare(strict_types=1);

namespace App\Domains\Backup\ValueObjects;

use InvalidArgumentException;

final readonly class BackupFrequency
{
    private const ALLOWED = ['daily', 'weekly', 'monthly'];

    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if (! in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidArgumentException("Invalid backup frequency: '{$value}'.");
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
