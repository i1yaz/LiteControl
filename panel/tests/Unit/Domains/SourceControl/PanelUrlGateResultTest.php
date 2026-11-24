<?php

declare(strict_types=1);

use App\Domains\SourceControl\ValueObjects\PanelUrlGateResult;
use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;
use Carbon\CarbonImmutable;

it('constructs allowed PanelUrlGateResult and serializes cleanly', function () {
    $expiresAt = CarbonImmutable::parse('2027-01-15T00:00:00Z');
    $result = PanelUrlGateResult::allowed('https://panel.example.com:8090', $expiresAt);

    expect($result->allowed)->toBeTrue();
    expect($result->code)->toBe(PanelUrlGateResult::CODE_OK);
    expect($result->configuredUrl)->toBe('https://panel.example.com:8090');
    expect($result->certExpiresAt?->toIso8601String())->toBe($expiresAt->toIso8601String());
    expect($result->toArray())->toBe([
        'allowed' => true,
        'code' => 'ok',
        'message' => 'Panel URL and SSL certificate satisfy Source Control requirements.',
        'configured_url' => 'https://panel.example.com:8090',
        'cert_expires_at' => $expiresAt->toIso8601String(),
    ]);
});

it('constructs denied PanelUrlGateResult for every documented machine-readable error code', function (string $code) {
    $result = PanelUrlGateResult::denied($code, 'Actionable failure message.', 'https://panel.example.com');

    expect($result->allowed)->toBeFalse();
    expect($result->code)->toBe($code);
    expect($result->message)->toBe('Actionable failure message.');
})->with([
    PanelUrlGateResult::CODE_NOT_HTTPS,
    PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST,
    PanelUrlGateResult::CODE_HOST_MISMATCH,
    PanelUrlGateResult::CODE_CERT_MISSING,
    PanelUrlGateResult::CODE_CERT_SELF_SIGNED,
    PanelUrlGateResult::CODE_CERT_EXPIRED,
    PanelUrlGateResult::CODE_CERT_HOST_MISMATCH,
]);

it('rejects invalid code or allowed/code mismatch in PanelUrlGateResult', function () {
    expect(fn () => new PanelUrlGateResult(true, PanelUrlGateResult::CODE_NOT_HTTPS, 'Mismatch'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new PanelUrlGateResult(false, PanelUrlGateResult::CODE_OK, 'Mismatch'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new PanelUrlGateResult(false, 'unknown_code', 'Unknown'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new PanelUrlGateResult(false, PanelUrlGateResult::CODE_NOT_HTTPS, '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('evaluates certificate expiry threshold accurately', function () {
    $now = CarbonImmutable::parse('2026-11-25T12:00:00Z');

    $expiringSoon = PanelUrlGateResult::allowed('https://panel.example.com', $now->addDays(10));
    expect($expiringSoon->expiresWithinDays(14, $now))->toBeTrue();
    expect($expiringSoon->daysUntilExpiry($now))->toBe(10);

    $healthy = PanelUrlGateResult::allowed('https://panel.example.com', $now->addDays(45));
    expect($healthy->expiresWithinDays(14, $now))->toBeFalse();
    expect($healthy->daysUntilExpiry($now))->toBe(45);
});

it('matches exact and single-level wildcard DNS names per RFC 6125', function () {
    $cert = SslCertificateInspectionResult::found(
        selfSigned: false,
        trusted: true,
        validFrom: CarbonImmutable::now()->subDay(),
        expiresAt: CarbonImmutable::now()->addDays(60),
        dnsNames: ['panel.example.com', '*.apps.example.org'],
    );

    expect($cert->matchesHost('panel.example.com'))->toBeTrue();
    expect($cert->matchesHost('PANEL.EXAMPLE.COM'))->toBeTrue();
    expect($cert->matchesHost('sub.apps.example.org'))->toBeTrue();
    expect($cert->matchesHost('apps.example.org'))->toBeFalse();
    expect($cert->matchesHost('a.b.apps.example.org'))->toBeFalse();
    expect($cert->matchesHost('other.example.com'))->toBeFalse();
});
