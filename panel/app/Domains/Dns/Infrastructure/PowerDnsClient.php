<?php

declare(strict_types=1);

namespace App\Domains\Dns\Infrastructure;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PowerDnsClient implements PowerDnsClientInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly ?PdnsSetting $setting = null,
    ) {}

    /**
     * Create a client instance bound to explicit credentials (e.g. during key rotation health probes).
     */
    public static function withSettings(PdnsSetting $setting): self
    {
        return new self($setting);
    }

    public function getServer(): array
    {
        return $this->requestJson('GET', '');
    }

    public function getConfig(): array
    {
        /** @var list<array<string, mixed>> $config */
        $config = $this->requestJson('GET', '/config');

        return $config;
    }

    public function listZones(?string $zoneName = null): array
    {
        $query = [];
        if ($zoneName !== null && trim($zoneName) !== '') {
            $query['zone'] = trim($zoneName);
        }

        /** @var list<array<string, mixed>> $zones */
        $zones = $this->requestJson('GET', '/zones', $query);

        return $zones;
    }

    public function getZone(string $zoneId): array
    {
        $encoded = $this->encodeZoneId($zoneId);

        return $this->requestJson('GET', "/zones/{$encoded}");
    }

    public function createZone(array $payload): array
    {
        return $this->requestJson('POST', '/zones', $payload);
    }

    public function updateZone(string $zoneId, array $payload): void
    {
        $encoded = $this->encodeZoneId($zoneId);
        $this->requestNoContent('PUT', "/zones/{$encoded}", $payload);
    }

    public function deleteZone(string $zoneId): void
    {
        $encoded = $this->encodeZoneId($zoneId);
        $this->requestNoContent('DELETE', "/zones/{$encoded}");
    }

    public function patchRrsets(string $zoneId, array $rrsets): void
    {
        $encoded = $this->encodeZoneId($zoneId);
        $this->requestNoContent('PATCH', "/zones/{$encoded}", [
            'rrsets' => array_values($rrsets),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $data = []): array
    {
        $response = $this->send($method, $path, $data);
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requestNoContent(string $method, string $path, array $data = []): void
    {
        $this->send($method, $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data = []): Response
    {
        $setting = $this->resolveSetting();
        $apiKey = trim((string) $setting->api_key);

        if ($apiKey === '') {
            throw new PowerDnsException(
                message: 'PowerDNS API key is not configured.',
                statusCode: 0,
                apiError: 'PowerDNS API key is not configured.',
                errors: [],
                method: strtoupper($method),
                endpoint: $this->normalizeEndpoint($path),
            );
        }

        $endpoint = $this->normalizeEndpoint($path);
        $url = rtrim($setting->baseUrl(), '/').($path !== '' ? '/'.ltrim($path, '/') : '');

        try {
            $request = $this->buildPendingRequest($apiKey);
            $upperMethod = strtoupper($method);

            $response = match ($upperMethod) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'PATCH' => $request->patch($url, $data),
                'DELETE' => $request->delete($url, $data),
                default => $request->send($upperMethod, $url, ['json' => $data]),
            };
        } catch (ConnectionException $e) {
            $sanitizedMessage = $this->redactSecret($e->getMessage(), $apiKey);

            Log::error('PowerDNS API connection failed', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'host' => $setting->host,
                'port' => $setting->port,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-Key' => '[REDACTED]',
                ],
                'error' => $sanitizedMessage,
            ]);

            throw new PowerDnsException(
                message: sprintf('Failed to connect to PowerDNS API on %s %s: %s', strtoupper($method), $endpoint, $sanitizedMessage),
                statusCode: 0,
                apiError: $sanitizedMessage,
                errors: [],
                method: strtoupper($method),
                endpoint: $endpoint,
                previous: $e,
            );
        } catch (Throwable $e) {
            $sanitizedMessage = $this->redactSecret($e->getMessage(), $apiKey);

            Log::error('PowerDNS API unexpected error', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-Key' => '[REDACTED]',
                ],
                'error' => $sanitizedMessage,
            ]);

            throw new PowerDnsException(
                message: sprintf('PowerDNS API request failed on %s %s: %s', strtoupper($method), $endpoint, $sanitizedMessage),
                statusCode: 0,
                apiError: $sanitizedMessage,
                errors: [],
                method: strtoupper($method),
                endpoint: $endpoint,
                previous: $e,
            );
        }

        if ($response->failed()) {
            $payload = $response->json();
            $normalizedPayload = is_array($payload) ? $payload : null;
            $rawBody = $this->redactSecret($response->body(), $apiKey);

            $exception = PowerDnsException::fromResponse(
                statusCode: $response->status(),
                payload: $normalizedPayload,
                rawBody: $rawBody,
                method: $method,
                endpoint: $endpoint,
            );

            Log::warning('PowerDNS API returned error response', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-Key' => '[REDACTED]',
                ],
                'api_error' => $exception->getApiError(),
                'errors' => $exception->getErrors(),
            ]);

            throw $exception;
        }

        return $response;
    }

    private function buildPendingRequest(string $apiKey): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ]);
    }

    private function resolveSetting(): PdnsSetting
    {
        return $this->setting ?? PdnsSetting::current();
    }

    private function encodeZoneId(string $zoneId): string
    {
        $trimmed = trim($zoneId);
        if ($trimmed === '') {
            throw new PowerDnsException(
                message: 'Zone ID cannot be empty.',
                statusCode: 0,
                apiError: 'Zone ID cannot be empty.',
            );
        }

        return rawurlencode($trimmed);
    }

    private function normalizeEndpoint(string $path): string
    {
        $suffix = $path !== '' ? '/'.ltrim($path, '/') : '';

        return '/api/v1/servers/localhost'.$suffix;
    }

    private function redactSecret(string $text, string $apiKey): string
    {
        if ($apiKey === '') {
            return $text;
        }

        return str_replace($apiKey, '[REDACTED]', $text);
    }
}
