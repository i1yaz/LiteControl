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

    /**
     * Retrieve all metadata entries for a zone (`GET /api/v1/servers/localhost/zones/{zone_id}/metadata`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws PowerDnsException
     */
    public function listZoneMetadata(string $zoneId): array;

    /**
     * Retrieve a single metadata kind for a zone (`GET /api/v1/servers/localhost/zones/{zone_id}/metadata/{metadata_kind}`).
     *
     * @return list<string>
     *
     * @throws PowerDnsException
     */
    public function getZoneMetadata(string $zoneId, string $metadataKind): array;

    /**
     * Replace a metadata kind for a zone (`PUT /api/v1/servers/localhost/zones/{zone_id}/metadata/{metadata_kind}`).
     *
     * @param  list<string>  $values
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function putZoneMetadata(string $zoneId, string $metadataKind, array $values): array;

    /**
     * Send DNS NOTIFY to all secondaries for a Primary zone (`PUT /api/v1/servers/localhost/zones/{zone_id}/notify`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function notifyZone(string $zoneId): array;

    /**
     * Trigger an immediate AXFR/IXFR transfer retrieval from upstream masters for a Secondary zone (`PUT /api/v1/servers/localhost/zones/{zone_id}/axfr-retrieve`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function axfrRetrieveZone(string $zoneId): array;

    /**
     * Rectify a zone's ordername/auth fields (`PUT /api/v1/servers/localhost/zones/{zone_id}/rectify`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function rectifyZone(string $zoneId): array;

    /**
     * Flush PowerDNS authoritative packet/query cache (`PUT /api/v1/servers/localhost/cache/flush?domain={domain}`).
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function flushCache(string $domain = '.'): array;

    /**
     * List all configured Autoprimaries / Supermasters (`GET /api/v1/servers/localhost/autoprimaries`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws PowerDnsException
     */
    public function listAutoprimaries(): array;

    /**
     * Add a trusted Autoprimary / Supermaster (`POST /api/v1/servers/localhost/autoprimaries`).
     *
     * @param  array{ip: string, nameserver: string, account?: string}  $payload
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function createAutoprimary(array $payload): array;

    /**
     * Remove an Autoprimary / Supermaster (`DELETE /api/v1/servers/localhost/autoprimaries/{ip}/{nameserver}`).
     *
     * @throws PowerDnsException
     */
    public function deleteAutoprimary(string $ip, string $nameserver): void;
}
