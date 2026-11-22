<?php

declare(strict_types=1);

namespace App\Domains\Dns\Exceptions;

use RuntimeException;
use Throwable;

final class PowerDnsException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?string $apiError = null,
        private readonly array $errors = [],
        private readonly string $method = '',
        private readonly string $endpoint = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Build a typed PowerDnsException from a PowerDNS HTTP API error response.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromResponse(
        int $statusCode,
        ?array $payload,
        string $rawBody,
        string $method,
        string $endpoint,
    ): self {
        $apiError = isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== ''
            ? trim($payload['error'])
            : null;

        $errors = [];
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            foreach ($payload['errors'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $errors[] = trim($item);
                }
            }
        }

        $detail = $apiError ?? (trim($rawBody) !== '' ? trim($rawBody) : "HTTP {$statusCode}");
        if ($errors !== []) {
            $detail .= ' ('.implode('; ', $errors).')';
        }

        $message = sprintf(
            'PowerDNS API error [%d] on %s %s: %s',
            $statusCode,
            strtoupper($method),
            $endpoint,
            $detail,
        );

        return new self(
            message: $message,
            statusCode: $statusCode,
            apiError: $apiError,
            errors: $errors,
            method: strtoupper($method),
            endpoint: $endpoint,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getApiError(): ?string
    {
        return $this->apiError;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
