<?php

declare(strict_types=1);

namespace App\Domains\Backup\ValueObjects;

use InvalidArgumentException;

final readonly class RetentionCount
{
    public int $value;

    public function __construct(int $value)
    {
        if ($value < 1 || $value > 30) {
            throw new InvalidArgumentException("Retention count must be between 1 and 30, got {$value}.");
        }

        $this->value = $value;
    }
}

