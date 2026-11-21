<?php

declare(strict_types=1);

namespace App\Domains\Mail\ValueObjects;

use InvalidArgumentException;

final readonly class SpamPolicy
{
    public function __construct(
        public bool $enabled,
        public float $headerScore,
        public float $rejectScore,
    ) {
        if ($headerScore <= 0.0 || $headerScore > 100.0) {
            throw new InvalidArgumentException("Spam header score must be between 0.1 and 100.0 (got {$headerScore}).");
        }

        if ($rejectScore <= 0.0 || $rejectScore > 100.0) {
            throw new InvalidArgumentException("Spam reject score must be between 0.1 and 100.0 (got {$rejectScore}).");
        }

        if ($rejectScore <= $headerScore) {
            throw new InvalidArgumentException('Spam reject score must be strictly greater than spam header score.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['spam_filter_enabled'] ?? true),
            headerScore: (float) ($data['spam_header_score'] ?? 6.0),
            rejectScore: (float) ($data['spam_reject_score'] ?? 15.0),
        );
    }
}
