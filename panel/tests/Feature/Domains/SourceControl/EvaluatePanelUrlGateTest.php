<?php

declare(strict_types=1);

use App\Console\Commands\CheckPanelSslExpiryCommand;
use App\Domains\SourceControl\Actions\EvaluatePanelUrlGateAction;
use App\Domains\SourceControl\Contracts\SslCertificateInspectorInterface;
use App\Domains\SourceControl\Infrastructure\OpenSslCertificateInspectorAdapter;
use App\Domains\SourceControl\ValueObjects\PanelUrlGateResult;
use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

function fakeSslInspector(SslCertificateInspectionResult $result): SslCertificateInspectorInterface
{
    return new class($result) implements SslCertificateInspectorInterface
    {
        public function __construct(
            private readonly SslCertificateInspectionResult $result,
        ) {}

        public function inspect(string $expectedHost, ?string $certPath = null): SslCertificateInspectionResult
        {
            return $this->result;
        }
    };
}

function validTrustedCert(
    array $dnsNames = ['panel.example.com'],
    ?CarbonImmutable $expiresAt = null,
): SslCertificateInspectionResult {
    return SslCertificateInspectionResult::found(
        selfSigned: false,
        trusted: true,
        validFrom: CarbonImmutable::now()->subDays(5),
        expiresAt: $expiresAt ?? CarbonImmutable::now()->addDays(60),
        dnsNames: $dnsNames,
        subject: 'CN=panel.example.com',
        issuer: 'CN=R10, O=Let\'s Encrypt, C=US',
    );
}

it('binds SslCertificateInspectorInterface to OpenSslCertificateInspectorAdapter', function () {
    expect(app(SslCertificateInspectorInterface::class))
        ->toBeInstanceOf(OpenSslCertificateInspectorAdapter::class);
});

it('rejects http URLs, IPv4/IPv6 addresses, localhost, and bare hostnames', function (string $url, string $expectedCode) {
    config()->set('app.url', $url);

    $action = new EvaluatePanelUrlGateAction(fakeSslInspector(validTrustedCert()));
    $result = $action->handle();

    expect($result->allowed)->toBeFalse();
    expect($result->code)->toBe($expectedCode);
})->with([
    'empty url' => ['', PanelUrlGateResult::CODE_NOT_HTTPS],
    'http scheme' => ['http://panel.example.com:8090', PanelUrlGateResult::CODE_NOT_HTTPS],
    'ipv4 address' => ['https://203.0.113.10:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'ipv6 loopback' => ['https://[::1]:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'ipv6 public literal' => ['https://[2001:db8::1]:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'localhost' => ['https://localhost:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'subdomain of localhost' => ['https://panel.localhost:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'dot local domain' => ['https://panel.local:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'dot internal domain' => ['https://panel.internal:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
    'bare hostname' => ['https://myserver:8090', PanelUrlGateResult::CODE_IP_OR_LOCAL_HOST],
]);

it('rejects when request host differs from configured panel URL host', function () {
    config()->set('app.url', 'https://panel.example.com:8090');

    $action = new EvaluatePanelUrlGateAction(fakeSslInspector(validTrustedCert()));
    $result = $action->handle(requestHost: '203.0.113.10');

    expect($result->allowed)->toBeFalse();
    expect($result->code)->toBe(PanelUrlGateResult::CODE_HOST_MISMATCH);
    expect($result->configuredUrl)->toBe('https://panel.example.com:8090');
});

it('rejects missing, self-signed, expired, or hostname-mismatched certificates', function (
    SslCertificateInspectionResult $cert,
    string $expectedCode,
) {
    config()->set('app.url', 'https://panel.example.com:8090');

    $action = new EvaluatePanelUrlGateAction(fakeSslInspector($cert));
    $result = $action->handle(requestHost: 'panel.example.com');

    expect($result->allowed)->toBeFalse();
    expect($result->code)->toBe($expectedCode);
})->with([
    'missing certificate' => [
        SslCertificateInspectionResult::missing('Certificate file missing'),
        PanelUrlGateResult::CODE_CERT_MISSING,
    ],
    'self-signed certificate' => [
        SslCertificateInspectionResult::found(
            selfSigned: true,
            trusted: false,
            validFrom: CarbonImmutable::now()->subDay(),
            expiresAt: CarbonImmutable::now()->addDays(60),
            dnsNames: ['panel.example.com'],
            subject: 'CN=panel.example.com',
            issuer: 'CN=panel.example.com',
        ),
        PanelUrlGateResult::CODE_CERT_SELF_SIGNED,
    ],
    'untrusted non-self-signed certificate' => [
        SslCertificateInspectionResult::found(
            selfSigned: false,
            trusted: false,
            validFrom: CarbonImmutable::now()->subDay(),
            expiresAt: CarbonImmutable::now()->addDays(60),
            dnsNames: ['panel.example.com'],
            subject: 'CN=panel.example.com',
            issuer: 'CN=Private Internal CA',
        ),
        PanelUrlGateResult::CODE_CERT_SELF_SIGNED,
    ],
    'expired certificate' => [
        SslCertificateInspectionResult::found(
            selfSigned: false,
            trusted: true,
            validFrom: CarbonImmutable::now()->subDays(90),
            expiresAt: CarbonImmutable::now()->subMinute(),
            dnsNames: ['panel.example.com'],
            subject: 'CN=panel.example.com',
            issuer: 'CN=R10, O=Let\'s Encrypt',
        ),
        PanelUrlGateResult::CODE_CERT_EXPIRED,
    ],
    'hostname-mismatched certificate' => [
        SslCertificateInspectionResult::found(
            selfSigned: false,
            trusted: true,
            validFrom: CarbonImmutable::now()->subDay(),
            expiresAt: CarbonImmutable::now()->addDays(60),
            dnsNames: ['other.example.com'],
            subject: 'CN=other.example.com',
            issuer: 'CN=R10, O=Let\'s Encrypt',
        ),
        PanelUrlGateResult::CODE_CERT_HOST_MISMATCH,
    ],
]);

it('passes when configured URL is https FQDN, request host matches, and cert is valid and trusted', function () {
    config()->set('app.url', 'https://panel.example.com:8090');
    $expiresAt = CarbonImmutable::now()->addDays(75);

    $action = new EvaluatePanelUrlGateAction(fakeSslInspector(validTrustedCert(['*.example.com'], $expiresAt)));
    $result = $action->handle(requestHost: 'PANEL.EXAMPLE.COM:8090');

    expect($result->allowed)->toBeTrue();
    expect($result->code)->toBe(PanelUrlGateResult::CODE_OK);
    expect($result->configuredUrl)->toBe('https://panel.example.com:8090');
    expect($result->certExpiresAt?->toIso8601String())->toBe($expiresAt->toIso8601String());
});

it('flags SSL renewal warning when certificate expires within 14 days', function () {
    config()->set('app.url', 'https://panel.example.com:8090');
    config()->set('litecontrol.panel_ssl_expiry_warning_days', 14);
    Cache::forget(CheckPanelSslExpiryCommand::WARNING_CACHE_KEY);

    $expiresAt = CarbonImmutable::now()->addDays(7);
    app()->instance(SslCertificateInspectorInterface::class, fakeSslInspector(validTrustedCert(['panel.example.com'], $expiresAt)));

    Log::spy();

    $this->artisan('source-control:check-panel-ssl-expiry')
        ->assertSuccessful();

    $warning = Cache::get(CheckPanelSslExpiryCommand::WARNING_CACHE_KEY);
    expect($warning)->toBeArray();
    expect($warning['warning'])->toBeTrue();
    expect($warning['code'])->toBe('cert_expiring_soon');
    expect($warning['days_remaining'])->toBeLessThanOrEqual(7);
    expect($warning['expires_at'])->toBe($expiresAt->toIso8601String());

    Log::shouldHaveReceived('warning')->once();
});

it('clears SSL renewal warning when certificate has more than 14 days remaining', function () {
    config()->set('app.url', 'https://panel.example.com:8090');
    config()->set('litecontrol.panel_ssl_expiry_warning_days', 14);
    Cache::put(CheckPanelSslExpiryCommand::WARNING_CACHE_KEY, ['warning' => true], now()->addHour());

    app()->instance(
        SslCertificateInspectorInterface::class,
        fakeSslInspector(validTrustedCert(['panel.example.com'], CarbonImmutable::now()->addDays(60))),
    );

    $this->artisan('source-control:check-panel-ssl-expiry')
        ->assertSuccessful();

    expect(Cache::get(CheckPanelSslExpiryCommand::WARNING_CACHE_KEY))->toBeNull();
});

it('returns missing inspection result cleanly when certificate file does not exist', function () {
    $adapter = new OpenSslCertificateInspectorAdapter;
    $result = $adapter->inspect('panel.example.com', sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent_lc_cert_'.uniqid().'.pem');

    expect($result->present)->toBeFalse();
    expect($result->error)->toContain('not found or unreadable');
});
