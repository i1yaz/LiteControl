<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\ValueObjects;

use InvalidArgumentException;

final readonly class PhpVersion
{
    public const SUPPORTED = ['74', '80', '81', '82', '83', '84', '85', '86'];

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
