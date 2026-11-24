<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\WebhookHealthResult;
use Carbon\CarbonImmutable;

class CheckGitProviderHealthAction
{
    public function __construct(
        private readonly GitProviderDriverInterface $driver,
    ) {}

    public function handle(GitProvider $provider): WebhookHealthResult
    {
        $health = $this->driver->getWebhookHealth($provider);

        $updates = [
            'last_verified_at' => CarbonImmutable::now(),
        ];

        if ($health->status === WebhookHealthResult::STATUS_VERIFIED && $provider->webhook_verified_at === null) {
            $updates['webhook_verified_at'] = $health->lastDeliveredAt ?? CarbonImmutable::now();
        }

        $provider->forceFill($updates)->save();

        return $health;
    }
}
