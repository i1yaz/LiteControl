<?php

namespace App\Domains\HostingPlans\ValueObjects;

use InvalidArgumentException;

final readonly class PackageName
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Package name cannot be empty.');
        }

        if (mb_strlen($trimmed) > 50) {
            throw new InvalidArgumentException('Package name cannot exceed 50 characters.');
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
