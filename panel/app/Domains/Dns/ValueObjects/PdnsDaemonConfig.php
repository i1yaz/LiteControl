<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use App\Domains\Dns\Models\PdnsSetting;
use InvalidArgumentException;

final readonly class PdnsDaemonConfig
{
    public string $host;

    public int $port;

    public string $webserverAllowFrom;

    public ?string $webserverPassword;

    public bool $primaryEnabled;

    public bool $secondaryEnabled;

    public bool $autosecondaryEnabled;

    public ?string $allowAxfrIps;

    public ?string $alsoNotify;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 8081,
        string $webserverAllowFrom = '127.0.0.1,::1',
        ?string $webserverPassword = null,
        bool $primaryEnabled = true,
        bool $secondaryEnabled = false,
        bool $autosecondaryEnabled = false,
        ?string $allowAxfrIps = null,
        ?string $alsoNotify = null,
        bool $hasExistingWebserverPassword = false,
    ) {
        $normalizedHost = trim($host);
        if ($normalizedHost === '' || filter_var($normalizedHost, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Webserver address '{$host}' is not a valid IPv4 or IPv6 address.");
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Webserver port {$port} must be between 1 and 65535.");
        }

        $allowEntries = self::parseCsvEntries($webserverAllowFrom);
        if ($allowEntries === []) {
            throw new InvalidArgumentException('At least one IP or CIDR must be specified in webserver_allow_from.');
        }

        foreach ($allowEntries as $entry) {
            if (! self::isValidIpOrCidr($entry)) {
                throw new InvalidArgumentException("Invalid IP or CIDR '{$entry}' in webserver_allow_from.");
            }
        }

        $normalizedPassword = $webserverPassword !== null && trim($webserverPassword) !== ''
            ? trim($webserverPassword)
            : null;

        if ($normalizedPassword !== null && (str_contains($normalizedPassword, "\r") || str_contains($normalizedPassword, "\n"))) {
            throw new InvalidArgumentException('Webserver password cannot contain newline characters.');
        }

        $isLoopback = self::isLoopbackIp($normalizedHost);
        if (! $isLoopback) {
            foreach ($allowEntries as $entry) {
                if (in_array($entry, ['0.0.0.0/0', '::/0'], true)) {
                    throw new InvalidArgumentException(
                        "webserver_allow_from cannot contain unrestricted '{$entry}' when webserver_address is non-loopback ({$normalizedHost})."
                    );
                }
            }

            if ($normalizedPassword === null && ! $hasExistingWebserverPassword) {
                throw new InvalidArgumentException(
                    "webserver_password is required when webserver_address is non-loopback ({$normalizedHost})."
                );
            }
        }

        $axfrEntries = self::parseCsvEntries((string) ($allowAxfrIps ?? ''));
        foreach ($axfrEntries as $entry) {
            if (! self::isValidIpOrCidr($entry)) {
                throw new InvalidArgumentException("Invalid IP or CIDR '{$entry}' in allow_axfr_ips.");
            }
        }

        $notifyEntries = self::parseCsvEntries((string) ($alsoNotify ?? ''));
        foreach ($notifyEntries as $entry) {
            if (! self::isValidIpWithOptionalPort($entry)) {
                throw new InvalidArgumentException("Invalid IP or IP:port '{$entry}' in also_notify.");
            }
        }

        $this->host = $normalizedHost;
        $this->port = $port;
        $this->webserverAllowFrom = implode(',', $allowEntries);
        $this->webserverPassword = $normalizedPassword;
        $this->primaryEnabled = $primaryEnabled;
        $this->secondaryEnabled = $secondaryEnabled;
        $this->autosecondaryEnabled = $autosecondaryEnabled;
        $this->allowAxfrIps = $axfrEntries !== [] ? implode(',', $axfrEntries) : null;
        $this->alsoNotify = $notifyEntries !== [] ? implode(',', $notifyEntries) : null;
    }

    /**
     * Build a PdnsDaemonConfig from validated request input.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $hasExistingWebserverPassword = false): self
    {
        return new self(
            host: (string) ($data['host'] ?? '127.0.0.1'),
            port: (int) ($data['port'] ?? 8081),
            webserverAllowFrom: (string) ($data['webserver_allow_from'] ?? '127.0.0.1,::1'),
            webserverPassword: isset($data['webserver_password']) && is_string($data['webserver_password'])
                ? $data['webserver_password']
                : null,
            primaryEnabled: (bool) ($data['primary_enabled'] ?? true),
            secondaryEnabled: (bool) ($data['secondary_enabled'] ?? false),
            autosecondaryEnabled: (bool) ($data['autosecondary_enabled'] ?? false),
            allowAxfrIps: isset($data['allow_axfr_ips']) && is_string($data['allow_axfr_ips'])
                ? $data['allow_axfr_ips']
                : null,
            alsoNotify: isset($data['also_notify']) && is_string($data['also_notify'])
                ? $data['also_notify']
                : null,
            hasExistingWebserverPassword: $hasExistingWebserverPassword,
        );
    }

    public static function fromSetting(PdnsSetting $setting): self
    {
        return new self(
            host: (string) ($setting->host ?: '127.0.0.1'),
            port: (int) ($setting->port ?: 8081),
            webserverAllowFrom: (string) ($setting->webserver_allow_from ?: '127.0.0.1,::1'),
            webserverPassword: $setting->hasWebserverPassword() ? (string) $setting->webserver_password : null,
            primaryEnabled: (bool) $setting->primary_enabled,
            secondaryEnabled: (bool) $setting->secondary_enabled,
            autosecondaryEnabled: (bool) $setting->autosecondary_enabled,
            allowAxfrIps: $setting->allow_axfr_ips !== null ? (string) $setting->allow_axfr_ips : null,
            alsoNotify: $setting->also_notify !== null ? (string) $setting->also_notify : null,
            hasExistingWebserverPassword: $setting->hasWebserverPassword(),
        );
    }

    public function isLoopback(): bool
    {
        return self::isLoopbackIp($this->host);
    }

    /**
     * Build the JSON payload expected by `lc-cli pdns apply-config --payload <json>`.
     *
     * @return array<string, mixed>
     */
    public function toCliPayload(string $apiKey, ?string $fallbackWebserverPassword = null): array
    {
        $resolvedPassword = $this->webserverPassword ?? $fallbackWebserverPassword;

        $payload = [
            'api_key' => trim($apiKey),
            'webserver_address' => $this->host,
            'webserver_port' => $this->port,
            'webserver_allow_from' => $this->webserverAllowFrom,
            'primary' => $this->primaryEnabled,
            'secondary' => $this->secondaryEnabled,
            'autosecondary' => $this->autosecondaryEnabled,
        ];

        if ($resolvedPassword !== null && trim($resolvedPassword) !== '') {
            $payload['webserver_password'] = trim($resolvedPassword);
        }

        if ($this->allowAxfrIps !== null && $this->allowAxfrIps !== '') {
            $payload['allow_axfr_ips'] = $this->allowAxfrIps;
        }

        if ($this->alsoNotify !== null && $this->alsoNotify !== '') {
            $payload['also_notify'] = $this->alsoNotify;
        }

        return $payload;
    }

    /**
     * Build Eloquent attributes for persisting into `pdns_settings`.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(string $apiKey, ?string $fallbackWebserverPassword = null): array
    {
        $resolvedPassword = $this->webserverPassword ?? $fallbackWebserverPassword;

        return [
            'host' => $this->host,
            'port' => $this->port,
            'api_key' => trim($apiKey),
            'webserver_allow_from' => $this->webserverAllowFrom,
            'webserver_password' => $resolvedPassword !== null && trim($resolvedPassword) !== ''
                ? trim($resolvedPassword)
                : null,
            'primary_enabled' => $this->primaryEnabled,
            'secondary_enabled' => $this->secondaryEnabled,
            'autosecondary_enabled' => $this->autosecondaryEnabled,
            'allow_axfr_ips' => $this->allowAxfrIps,
            'also_notify' => $this->alsoNotify,
        ];
    }

    public static function isLoopbackIp(string $ip): bool
    {
        $trimmed = trim($ip);
        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($trimmed, '127.');
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($trimmed);

            return $packed === inet_pton('::1');
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function parseCsvEntries(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $entries = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $entries[] = $trimmed;
            }
        }

        return $entries;
    }

    public static function isValidIpOrCidr(string $entry): bool
    {
        $trimmed = trim($entry);
        if ($trimmed === '') {
            return false;
        }

        if (! str_contains($trimmed, '/')) {
            return filter_var($trimmed, FILTER_VALIDATE_IP) !== false;
        }

        [$ip, $prefix] = explode('/', $trimmed, 2);
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        $prefixInt = (int) $prefix;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 128;
        }

        return false;
    }

    public static function isValidIpWithOptionalPort(string $entry): bool
    {
        $trimmed = trim($entry);
        if ($trimmed === '') {
            return false;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Bracketed IPv6 with port: [2001:db8::1]:5300
        if (preg_match('/^\[([^\]]+)\]:([0-9]+)$/', $trimmed, $matches) === 1) {
            $port = (int) $matches[2];

            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && $port >= 1
                && $port <= 65535;
        }

        // IPv4 with port: 203.0.113.10:53
        if (substr_count($trimmed, ':') === 1) {
            [$ip, $portStr] = explode(':', $trimmed, 2);
            if ($portStr === '' || ! ctype_digit($portStr)) {
                return false;
            }
            $port = (int) $portStr;

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && $port >= 1
                && $port <= 65535;
        }

        return false;
    }
}
