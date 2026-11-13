<?php

declare(strict_types=1);

namespace App\Domains\Identity\ValueObjects;

use InvalidArgumentException;

final readonly class LinuxUsername
{
    private const RESERVED = [
        'root',
        'admin',
        'litecontrol',
        'www-data',
        'nobody',
        'vmail',
        'mysql',
        'redis',
    ];

    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (! preg_match('/^[a-z][a-z0-9_]{2,15}$/', $trimmed)) {
            throw new InvalidArgumentException(
                "Invalid Linux username '{$value}'. Must be 3-16 lowercase alphanumeric characters or underscores, starting with a letter."
            );
        }

        if (in_array($trimmed, self::RESERVED, true)) {
            throw new InvalidArgumentException("Username '{$trimmed}' is reserved.");
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

