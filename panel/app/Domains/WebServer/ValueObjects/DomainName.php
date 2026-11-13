<?php

namespace App\Domains\WebServer\ValueObjects;

use InvalidArgumentException;

final readonly class DomainName
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $normalized)) {
            throw new InvalidArgumentException("Invalid domain name: '{$value}'.");
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

