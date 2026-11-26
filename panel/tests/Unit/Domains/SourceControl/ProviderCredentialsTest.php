<?php

declare(strict_types=1);

use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\ValueObjects\ProviderCredentials;

it('defines all expected git provider status values', function () {
    expect(array_column(GitProviderStatus::cases(), 'value'))->toBe([
        'pending_installation',
        'active',
        'suspended',
        'reauth_required',
        'source_unavailable',
        'decryption_failed',
    ]);
});

it('defines manual and provider source modes', function () {
    expect(array_column(SourceMode::cases(), 'value'))->toBe([
        'manual',
        'provider',
    ]);
});

it('parses and serializes valid provider credentials and redacts secrets in debug info', function () {
    $creds = ProviderCredentials::fromArray([
        'pem' => "-----BEGIN RSA PRIVATE KEY-----\nTEST\n-----END RSA PRIVATE KEY-----",
        'client_id' => 'Iv1.1234567890abcdef',
        'client_secret' => 'super-secret-client-secret',
    ]);

    expect($creds->clientId)->toBe('Iv1.1234567890abcdef');
    expect($creds->toArray())->toBe([
        'pem' => "-----BEGIN RSA PRIVATE KEY-----\nTEST\n-----END RSA PRIVATE KEY-----",
        'client_id' => 'Iv1.1234567890abcdef',
        'client_secret' => 'super-secret-client-secret',
    ]);

    expect($creds->__debugInfo())->toBe([
        'pem' => '[REDACTED]',
        'client_id' => 'Iv1.1234567890abcdef',
        'client_secret' => '[REDACTED]',
    ]);
});

it('rejects empty or malformed provider credentials', function (array $payload) {
    expect(fn () => ProviderCredentials::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing keys' => [[]],
    'empty pem' => [['pem' => '   ', 'client_id' => 'id', 'client_secret' => 'secret']],
    'empty client_id' => [['pem' => 'pem-data', 'client_id' => '', 'client_secret' => 'secret']],
    'empty client_secret' => [['pem' => 'pem-data', 'client_id' => 'id', 'client_secret' => '']],
    'non-string pem' => [['pem' => 123, 'client_id' => 'id', 'client_secret' => 'secret']],
]);
