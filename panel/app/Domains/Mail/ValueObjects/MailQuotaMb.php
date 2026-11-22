<?php

declare(strict_types=1);

namespace App\Domains\Mail\ValueObjects;

use InvalidArgumentException;

final readonly class MailQuotaMb
{
    public int $value;

    public function __construct(int $quotaMb)
    {
        if ($quotaMb < 0) {
            throw new InvalidArgumentException("Mail quota must be non-negative, got {$quotaMb}.");
        }

        $this->value = $quotaMb;
    }

    public function isUnlimited(): bool
    {
        return $this->value === 0;
    }
}
