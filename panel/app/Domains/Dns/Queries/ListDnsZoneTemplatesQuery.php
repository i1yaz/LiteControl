<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Models\DnsZoneTemplate;

final class ListDnsZoneTemplatesQuery
{
    /**
     * Retrieve all DNS zone templates ordered with the default template first.
     * Auto-seeds the default "Standard Web & Mail" template if the table is completely empty.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(): array
    {
        if (DnsZoneTemplate::query()->count() === 0) {
            DnsZoneTemplate::ensureDefaultSeeded();
        }

        return DnsZoneTemplate::query()
            ->with('records')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(static fn (DnsZoneTemplate $template): array => $template->toPublicArray())
            ->values()
            ->all();
    }
}
