<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

final readonly class WebhookProcessingResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $httpStatus,
        public string $status,
        public ?string $reason = null,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'reason' => $this->reason,
            ...$this->meta,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
