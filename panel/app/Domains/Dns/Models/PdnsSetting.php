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

    public function baseUrl(): string
    {
        $host = trim((string) $this->host);
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        return sprintf('http://%s:%d/api/v1/servers/localhost', $host, (int) $this->port);
    }
}
