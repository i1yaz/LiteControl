<?php

declare(strict_types=1);

namespace App\Domains\Dns\Models;

use Illuminate\Database\Eloquent\Model;

class PdnsSetting extends Model
{
    protected $table = 'pdns_settings';

    protected $fillable = [
        'host',
        'port',
        'api_key',
        'webserver_allow_from',
        'webserver_password',
        'primary_enabled',
        'secondary_enabled',
        'autosecondary_enabled',
        'allow_axfr_ips',
        'also_notify',
        'default_nameservers',
    ];

    protected $hidden = [
        'api_key',
        'webserver_password',
    ];

    protected $attributes = [
        'host' => '127.0.0.1',
        'port' => 8081,
        'webserver_allow_from' => '127.0.0.1,::1',
        'primary_enabled' => true,
        'secondary_enabled' => false,
        'autosecondary_enabled' => false,
        'default_nameservers' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'api_key' => 'encrypted',
            'webserver_password' => 'encrypted',
            'primary_enabled' => 'boolean',
            'secondary_enabled' => 'boolean',
            'autosecondary_enabled' => 'boolean',
            'default_nameservers' => 'array',
        ];
    }

    /**
     * Retrieve the singleton PowerDNS settings record, creating one with safe defaults if none exists.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'host' => '127.0.0.1',
            'port' => 8081,
            'webserver_allow_from' => '127.0.0.1,::1',
            'primary_enabled' => true,
            'secondary_enabled' => false,
            'autosecondary_enabled' => false,
            'default_nameservers' => [],
        ]);
    }

    public function hasApiKey(): bool
    {
        return is_string($this->api_key) && trim($this->api_key) !== '';
    }

    public function hasWebserverPassword(): bool
    {
        return is_string($this->webserver_password) && trim($this->webserver_password) !== '';
    }

    public function baseUrl(): string
    {
        $host = trim((string) $this->host);
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        return sprintf('http://%s:%d/api/v1/servers/localhost', $host, (int) $this->port);
    }

    /**
     * Normalize stored default nameservers into structured entries with base domain metadata.
     *
     * @return list<array{hostname: string, ipv4: string, ipv6: string, base_domain: string}>
     */
    public function normalizedDefaultNameservers(): array
    {
        $raw = $this->default_nameservers ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $hostname = strtolower(rtrim(trim($item), '.'));
                $ipv4 = '';
                $ipv6 = '';
            } elseif (is_array($item)) {
                $hostname = strtolower(rtrim(trim((string) ($item['hostname'] ?? '')), '.'));
                $ipv4 = trim((string) ($item['ipv4'] ?? ''));
                $ipv6 = trim((string) ($item['ipv6'] ?? ''));
            } else {
                continue;
            }

            if ($hostname === '') {
                continue;
            }

            $canonicalHostname = $hostname.'.';
            $labels = explode('.', $hostname);
            $baseDomain = count($labels) > 2
                ? implode('.', array_slice($labels, 1)).'.'
                : $canonicalHostname;

            $entries[] = [
                'hostname' => $canonicalHostname,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
                'base_domain' => $baseDomain,
            ];
        }

        return $entries;
    }

    /**
     * Safe representation for Inertia props (never exposes raw api_key or webserver_password).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'host' => (string) $this->host,
            'port' => (int) $this->port,
            'webserver_allow_from' => (string) ($this->webserver_allow_from ?? '127.0.0.1,::1'),
            'has_api_key' => $this->hasApiKey(),
            'has_webserver_password' => $this->hasWebserverPassword(),
            'primary_enabled' => (bool) $this->primary_enabled,
            'secondary_enabled' => (bool) $this->secondary_enabled,
            'autosecondary_enabled' => (bool) $this->autosecondary_enabled,
            'allow_axfr_ips' => (string) ($this->allow_axfr_ips ?? ''),
            'also_notify' => (string) ($this->also_notify ?? ''),
            'default_nameservers' => $this->normalizedDefaultNameservers(),
            'base_url' => $this->baseUrl(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
