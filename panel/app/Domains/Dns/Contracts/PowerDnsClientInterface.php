<?php

declare(strict_types=1);

namespace App\Domains\Dns\Contracts;

use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;

interface PowerDnsClientInterface
{
    /**
     * Return a client instance bound to explicit PowerDNS settings (e.g. during key rotation health probes).
     */
    public function withSettings(PdnsSetting $setting): self;

    /**
     * Retrieve PowerDNS server metadata (`GET /api/v1/servers/localhost`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function getServer(): array;

    /**
     * Retrieve active PowerDNS daemon configuration (`GET /api/v1/servers/localhost/config`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws PowerDnsException
     */
    public function getConfig(): array;

    /**
     * List all zones managed by the server (`GET /api/v1/servers/localhost/zones`).
     *
     * @param  string|null  $zoneName  Optional canonical zone filter
     * @return list<array<string, mixed>>
     *
     * @throws PowerDnsException
     */
    public function listZones(?string $zoneName = null): array;

    /**
     * Retrieve a single zone including its RRSets (`GET /api/v1/servers/localhost/zones/{zone_id}`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function getZone(string $zoneId): array;

    /**
     * Create a new zone (`POST /api/v1/servers/localhost/zones`).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function createZone(array $payload): array;

    /**
     * Update zone-level metadata/kind/masters (`PUT /api/v1/servers/localhost/zones/{zone_id}`).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws PowerDnsException
     */
    public function updateZone(string $zoneId, array $payload): void;

    /**
     * Delete a zone (`DELETE /api/v1/servers/localhost/zones/{zone_id}`).
     *
     * @throws PowerDnsException
     */
    public function deleteZone(string $zoneId): void;

    /**
     * Atomically create, replace, extend, prune, or delete RRSets in a zone (`PATCH /api/v1/servers/localhost/zones/{zone_id}`).
     *
     * @param  list<array<string, mixed>>  $rrsets
     *
     * @throws PowerDnsException
     */
    public function patchRrsets(string $zoneId, array $rrsets): void;

    /**
     * Search records, zones, and comments across all zones (`GET /api/v1/servers/localhost/search-data`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws PowerDnsException
     */
    public function searchData(string $query, int $max = 100, string $objectType = 'all'): array;

    /**
     * Export a zone in BIND/AXFR format (`GET /api/v1/servers/localhost/zones/{zone_id}/export`).
     *
     * @throws PowerDnsException
     */
    public function exportZone(string $zoneId): string;
}
