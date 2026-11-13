<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\ValueObjects;

use InvalidArgumentException;

final readonly class PhpExtensionName
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! preg_match('/^[a-zA-Z0-9_-]+$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid PHP extension name: '{$value}'.");
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

