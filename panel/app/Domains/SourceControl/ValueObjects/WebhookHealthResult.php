<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class WebhookHealthResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILING = 'failing';

    public const STATUS_NONE_YET = 'none_yet';

    /**
     * @param  list<array<string, mixed>>  $recentDeliveries
     */
    public function __construct(
        public string $status,
        public ?int $lastStatusCode = null,
        public ?CarbonImmutable $lastDeliveredAt = null,
        public array $recentDeliveries = [],
    ) {}

    /**
     * @return array{status: string, last_status_code: ?int, last_delivered_at: ?string, recent_deliveries: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'last_status_code' => $this->lastStatusCode,
            'last_delivered_at' => $this->lastDeliveredAt?->toIso8601String(),
            'recent_deliveries' => $this->recentDeliveries,
        ];
    }
}
