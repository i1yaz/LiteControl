<?php

declare(strict_types=1);

namespace App\Domains\Mail\ValueObjects;

use InvalidArgumentException;

final readonly class DkimSelector
{
    private const PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public string $value;

    public function __construct(string $selector)
    {
        $normalized = strtolower(trim($selector));

        if ($normalized === '' || ! preg_match(self::PATTERN, $normalized)) {
            throw new InvalidArgumentException(
                "Invalid DKIM selector '{$selector}'. Must match ^[a-z0-9][a-z0-9-]{0,62}$."
            );
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
