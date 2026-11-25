<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\SourceControl\Contracts\SslCertificateInspectorInterface;
use App\Domains\SourceControl\ValueObjects\PanelUrlGateResult;

class EvaluatePanelUrlGateAction
{
    /** @var list<string> */
    private const FORBIDDEN_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.localdomain',
        '.home.arpa',
    ];

    public function __construct(
        private readonly SslCertificateInspectorInterface $certificateInspector,
    ) {}

    public function handle(?string $requestHost = null, ?string $configuredUrl = null): PanelUrlGateResult
    {
        $url = $this->resolveConfiguredUrl($configuredUrl);

        $parsed = $url !== '' ? parse_url($url) : false;
        $scheme = is_array($parsed) && is_string($parsed['scheme'] ?? null)
            ? strtolower($parsed['scheme'])
            : '';
        $rawHost = is_array($parsed) && is_string($parsed['host'] ?? null)
            ? $parsed['host']
            : '';

        if ($scheme !== 'https' || $rawHost === '') {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_NOT_HTTPS,
                message: 'LiteControl must be configured with an HTTPS panel URL before connecting a Source Control provider. Configure Panel SSL with a valid domain first.',
                configuredUrl: $url !== '' ? $url : null,
            );
        }

        $configuredHost = $this->normalizeHost($rawHost);
        if (! $this->isValidPublicFqdn($configuredHost)) {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST,
                message: "Configured panel host [{$configuredHost}] is not a publicly routable domain name. IP addresses, localhost, internal domains, and bare hostnames are not supported for GitHub App callbacks or webhooks.",
                configuredUrl: $url,
            );
        }

        if ($requestHost !== null) {
            $normalizedRequestHost = $this->normalizeRequestHost($requestHost);
            if ($normalizedRequestHost === '' || $normalizedRequestHost !== $configuredHost) {
                return PanelUrlGateResult::denied(
                    code: PanelUrlGateResult::CODE_HOST_MISMATCH,
                    message: "Current request host [{$requestHost}] does not match the configured panel host [{$configuredHost}]. Access LiteControl via {$url} so session cookies survive the provider callback.",
                    configuredUrl: $url,
                );
            }
        }

        $certificate = $this->certificateInspector->inspect($configuredHost);

        if (! $certificate->present) {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_CERT_MISSING,
                message: 'Panel TLS certificate could not be verified.',
                configuredUrl: $url,
            );
        }

        if ($certificate->selfSigned || ! $certificate->trusted) {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_CERT_SELF_SIGNED,
                message: 'The panel TLS certificate is self-signed or not trusted by the system CA bundle. GitHub webhooks require a publicly trusted certificate (e.g. Let\'s Encrypt).',
                configuredUrl: $url,
                certExpiresAt: $certificate->expiresAt,
            );
        }

        if ($certificate->isExpired()) {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_CERT_EXPIRED,
                message: 'The panel TLS certificate has expired or is not yet valid. Renew the certificate in Security → Panel SSL before continuing.',
                configuredUrl: $url,
                certExpiresAt: $certificate->expiresAt,
            );
        }

        if (! $certificate->matchesHost($configuredHost)) {
            return PanelUrlGateResult::denied(
                code: PanelUrlGateResult::CODE_CERT_HOST_MISMATCH,
                message: "The panel TLS certificate does not cover the configured host [{$configuredHost}]. Re-issue the certificate for this hostname.",
                configuredUrl: $url,
                certExpiresAt: $certificate->expiresAt,
            );
        }

        return PanelUrlGateResult::allowed(
            configuredUrl: $url,
            certExpiresAt: $certificate->expiresAt,
        );
    }

    private function resolveConfiguredUrl(?string $configuredUrl): string
    {
        if ($configuredUrl !== null) {
            return trim($configuredUrl);
        }

        $url = trim((string) config('app.url', ''));
        if ($this->isHttpsPublicFqdnUrl($url)) {
            return $url;
        }

        // If config:cache is stale (e.g. still holding the initial server IP after Panel SSL was issued),
        // read APP_URL directly from .env so the live configured domain is honored immediately.
        if (function_exists('app') && app()->configurationIsCached()) {
            $envUrl = $this->readAppUrlFromEnvFile();
            if ($envUrl !== null && $this->isHttpsPublicFqdnUrl($envUrl)) {
                return $envUrl;
            }
        }

        // Fall back to /etc/litecontrol/panel_domain (written by lc-cli ssl panel-ssl) when present.
        $panelDomainUrl = $this->readPanelDomainUrl($url);
        if ($panelDomainUrl !== null) {
            return $panelDomainUrl;
        }

        return $url;
    }

    private function isHttpsPublicFqdnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            return false;
        }

        $scheme = is_string($parsed['scheme'] ?? null) ? strtolower($parsed['scheme']) : '';
        $host = is_string($parsed['host'] ?? null) ? $this->normalizeHost($parsed['host']) : '';

        return $scheme === 'https' && $this->isValidPublicFqdn($host);
    }

    private function readAppUrlFromEnvFile(): ?string
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_readable($envPath)) {
            return null;
        }

        $contents = @file_get_contents($envPath);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        if (! preg_match('/^\s*APP_URL\s*=\s*(.+)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1]);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    private function readPanelDomainUrl(string $fallbackUrl): ?string
    {
        $domainFile = (string) config('litecontrol.panel_domain_file', '/etc/litecontrol/panel_domain');
        if ($domainFile === '' || ! is_file($domainFile) || ! is_readable($domainFile)) {
            return null;
        }

        $domain = $this->normalizeHost(trim((string) @file_get_contents($domainFile)));
        if (! $this->isValidPublicFqdn($domain)) {
            return null;
        }

        $port = null;
        $portFile = (string) config('litecontrol.panel_port_file', '/etc/litecontrol/port');
        if ($portFile !== '' && is_file($portFile) && is_readable($portFile)) {
            $filePort = (int) trim((string) @file_get_contents($portFile));
            if ($filePort > 0 && $filePort <= 65535) {
                $port = $filePort;
            }
        }

        if ($port === null && $fallbackUrl !== '') {
            $parsedPort = parse_url($fallbackUrl, PHP_URL_PORT);
            if (is_int($parsedPort) && $parsedPort > 0 && $parsedPort <= 65535) {
                $port = $parsedPort;
            }
        }

        return ($port !== null && $port !== 443)
            ? "https://{$domain}:{$port}"
            : "https://{$domain}";
    }

    private function normalizeHost(string $host): string
    {
        $unbracketed = trim(trim($host), '[]');

        return rtrim(strtolower($unbracketed), '.');
    }

    private function normalizeRequestHost(string $requestHost): string
    {
        $trimmed = trim($requestHost);
        if ($trimmed === '') {
            return '';
        }

        // Bracketed IPv6 with optional port: [::1]:8090
        if (str_starts_with($trimmed, '[')) {
            $closing = strpos($trimmed, ']');
            $host = $closing !== false ? substr($trimmed, 1, $closing - 1) : trim($trimmed, '[]');

            return rtrim(strtolower($host), '.');
        }

        // Hostname or IPv4 with optional :port (single colon)
        if (substr_count($trimmed, ':') === 1) {
            $trimmed = explode(':', $trimmed, 2)[0];
        }

        return rtrim(strtolower($trimmed), '.');
    }

    private function isValidPublicFqdn(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', $host) === 1;
    }
}
