<?php

declare(strict_types=1);

use App\Domains\SourceControl\ValueObjects\ResolvedFetchPayload;

it('builds versioned tarball fetch payload and redacts secrets from debug info', function () {
    $payload = new ResolvedFetchPayload(
        mode: ResolvedFetchPayload::MODE_TARBALL,
        commitSha: 'deadbeef1234567890',
        branch: 'main',
        tarballUrl: 'https://api.github.com/repositories/42/tarball/deadbeef1234567890',
        authHeader: 'Bearer ghs_super_secret_token_123',
    );

    $decoded = json_decode($payload->toStdinJson(), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toMatchArray([
        'schema_version' => 1,
        'mode' => 'tarball',
        'commit_sha' => 'deadbeef1234567890',
        'branch' => 'main',
        'tarball_url' => 'https://api.github.com/repositories/42/tarball/deadbeef1234567890',
        'auth_header' => 'Bearer ghs_super_secret_token_123',
    ]);

    $debug = $payload->__debugInfo();
    expect($debug['auth_header'])->toBe('[REDACTED]');
    expect(json_encode($debug))->not->toContain('ghs_super_secret_token_123');

    expect($payload->redactText('Failed with Bearer ghs_super_secret_token_123 and ghs_super_secret_token_123'))
        ->toBe('Failed with [REDACTED] and [REDACTED]');
});

it('builds versioned shallow_clone fetch payload and redacts https token', function () {
    $payload = new ResolvedFetchPayload(
        mode: ResolvedFetchPayload::MODE_SHALLOW_CLONE,
        commitSha: 'cafebabe9876543210',
        branch: 'release/v2',
        cloneUrl: 'https://github.com/acme/webapp.git',
        httpsUsername: 'x-access-token',
        httpsToken: 'ghs_clone_secret_456',
    );

    $decoded = json_decode($payload->toStdinJson(), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toMatchArray([
        'schema_version' => 1,
        'mode' => 'shallow_clone',
        'commit_sha' => 'cafebabe9876543210',
        'branch' => 'release/v2',
        'clone_url' => 'https://github.com/acme/webapp.git',
        'https_username' => 'x-access-token',
        'https_token' => 'ghs_clone_secret_456',
    ]);

    expect($payload->toRedactedArray()['https_token'])->toBe('[REDACTED]');
    expect($payload->redactText('fatal: auth failed for ghs_clone_secret_456'))
        ->toBe('fatal: auth failed for [REDACTED]');
});

it('rejects unsupported schema versions or missing mode-specific fields', function () {
    expect(fn () => new ResolvedFetchPayload(
        mode: ResolvedFetchPayload::MODE_TARBALL,
        commitSha: 'abc123',
        tarballUrl: 'https://api.github.com/repositories/1/tarball/abc123',
        authHeader: 'Bearer tok',
        schemaVersion: 2,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ResolvedFetchPayload(
        mode: ResolvedFetchPayload::MODE_TARBALL,
        commitSha: 'abc123',
        tarballUrl: null,
        authHeader: 'Bearer tok',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ResolvedFetchPayload(
        mode: ResolvedFetchPayload::MODE_SHALLOW_CLONE,
        commitSha: 'abc123',
        branch: 'main',
        cloneUrl: 'https://github.com/acme/app.git',
        httpsUsername: 'x-access-token',
        httpsToken: null,
    ))->toThrow(InvalidArgumentException::class);
});
