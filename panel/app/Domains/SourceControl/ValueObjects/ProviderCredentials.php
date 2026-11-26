<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use InvalidArgumentException;

final readonly class ProviderCredentials
{
    public function __construct(
        public string $pem,
        public string $clientId,
        public string $clientSecret,
    ) {
        if (trim($pem) === '') {
            throw new InvalidArgumentException('Provider credentials PEM private key cannot be empty.');
        }

        if (trim($clientId) === '') {
            throw new InvalidArgumentException('Provider credentials client ID cannot be empty.');
        }

        if (trim($clientSecret) === '') {
            throw new InvalidArgumentException('Provider credentials client secret cannot be empty.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pem = $data['pem'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;

        if (! is_string($pem) || ! is_string($clientId) || ! is_string($clientSecret)) {
            throw new InvalidArgumentException('Provider credentials payload must contain string pem, client_id, and client_secret.');
        }

        return new self(
            pem: $pem,
            clientId: $clientId,
            clientSecret: $clientSecret,
        );
    }

    /**
     * @return array{pem: string, client_id: string, client_secret: string}
     */
    public function toArray(): array
    {
        return [
            'pem' => $this->pem,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    /**
     * @return array{pem: string, client_id: string, client_secret: string}
     */
    public function __debugInfo(): array
    {
        return [
            'pem' => '[REDACTED]',
            'client_id' => $this->clientId,
            'client_secret' => '[REDACTED]',
        ];
    }
}
