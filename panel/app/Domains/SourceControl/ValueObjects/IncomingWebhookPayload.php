<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use Illuminate\Http\Request;

final readonly class IncomingWebhookPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public ?string $deliveryId,
        public string $signature,
        public string $rawBody,
        public array $payload,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);

        $deliveryHeader = $request->header('X-GitHub-Delivery');
        $deliveryId = is_string($deliveryHeader) && trim($deliveryHeader) !== ''
            ? trim($deliveryHeader)
            : null;

        return new self(
            event: strtolower(trim((string) $request->header('X-GitHub-Event', ''))),
            deliveryId: $deliveryId,
            signature: trim((string) $request->header('X-Hub-Signature-256', '')),
            rawBody: $rawBody,
            payload: is_array($decoded) ? $decoded : [],
        );
    }

    public function verifySignature(string $secret): bool
    {
        if ($secret === '' || $this->signature === '' || ! str_starts_with($this->signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $this->rawBody, $secret);

        return hash_equals($expected, $this->signature);
    }
}
