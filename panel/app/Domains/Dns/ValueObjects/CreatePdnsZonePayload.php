<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class CreatePdnsZonePayload
{
    public const ALLOWED_KINDS = ['Native', 'Primary', 'Secondary'];

    /**
     * @param  list<string>  $nameservers
     * @param  list<string>  $masters
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $zoneType,
        public array $nameservers = [],
        public array $masters = [],
        public string $soaEditApi = 'DEFAULT',
        public bool $apiRectify = true,
        public ?int $templateId = null,
        public ?string $templateIpv4 = null,
        public ?string $templateIpv6 = null,
    ) {
        if (! in_array($this->kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException("Unsupported PowerDNS zone kind: {$this->kind}");
        }

        if ($this->kind === 'Secondary' && $this->masters === []) {
            throw new InvalidArgumentException('Secondary zones require at least one upstream primary (master) IP address.');
        }
    }

    /**
     * Build the PowerDNS HTTP API v1 payload for `POST /api/v1/servers/localhost/zones`.
     *
     * @return array<string, mixed>
     */
    public function toPowerDnsPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'kind' => $this->kind,
            'nameservers' => $this->kind === 'Secondary' ? [] : array_values($this->nameservers),
        ];

        if (in_array($this->kind, ['Native', 'Primary'], true)) {
            $payload['soa_edit_api'] = $this->soaEditApi;
            $payload['api_rectify'] = $this->apiRectify;
        }

        if ($this->kind === 'Secondary') {
            $payload['masters'] = array_values($this->masters);
        }

        return $payload;
    }
}
