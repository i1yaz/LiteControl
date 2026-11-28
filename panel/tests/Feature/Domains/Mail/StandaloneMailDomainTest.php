<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\EnableMailDomainAction;
use App\Domains\Mail\Actions\IssueStandaloneMailTlsAction;
use App\Domains\Mail\Contracts\DnsTxtResolverInterface;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Listeners\SyncMailSniOnSslIssuedListener;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\DnsTxtVerificationToken;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\Events\SslCertificateIssued;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Exceptions\System\LcCliException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('allows a tenant without a hosted website to verify domain ownership via DNS TXT and store ownership_source=dns_txt', function () {
    Queue::fake();
    Event::fake([MailDnsRecordsChanged::class]);

    $tenant = User::factory()->create([
        'username' => 'tenant_dns',
        'role' => 'user',
    ]);
    $domain = 'standalone-mail.acme-tenant.net';
    $expectedToken = DnsTxtVerificationToken::forUserAndDomain(
        $tenant->id,
        $domain,
        (string) config('app.key'),
    );

    $resolver = Mockery::mock(DnsTxtResolverInterface::class);
    $resolver->shouldReceive('resolveTxtRecords')
        ->once()
        ->with('_lc-mail-verify.standalone-mail.acme-tenant.net')
        ->andReturn(['v=spf1 ~all', $expectedToken->value]);
    $this->app->instance(DnsTxtResolverInterface::class, $resolver);

    $mailDomain = app(EnableMailDomainAction::class)->execute(
        actor: $tenant,
        owner: $tenant,
        domain: new DomainName($domain),
        verifyDnsTxt: true,
    );

    expect($mailDomain->ownership_source)->toBe('dns_txt')
        ->and($mailDomain->user_id)->toBe($tenant->id)
        ->and($mailDomain->domain)->toBe($domain)
        ->and($mailDomain->active)->toBeTrue();

    Queue::assertPushed(ReconcileMailServerJob::class, fn ($job) => $job->scope === 'all');
    Event::assertDispatched(MailDnsRecordsChanged::class, fn ($e) => $e->domain === $domain);
});

it('rejects DNS TXT verification when the expected TXT record is missing or mismatched', function () {
    $tenant = User::factory()->create([
        'username' => 'tenant_unverified',
        'role' => 'user',
    ]);
    $domain = 'unverified-mail.acme-tenant.net';

    $resolver = Mockery::mock(DnsTxtResolverInterface::class);
    $resolver->shouldReceive('resolveTxtRecords')
        ->once()
        ->with('_lc-mail-verify.unverified-mail.acme-tenant.net')
        ->andReturn(['lc-mail-verify=00000000000000000000000000000000']);
    $this->app->instance(DnsTxtResolverInterface::class, $resolver);

    expect(fn () => app(EnableMailDomainAction::class)->execute(
        actor: $tenant,
        owner: $tenant,
        domain: new DomainName($domain),
        verifyDnsTxt: true,
    ))->toThrow(ValidationException::class);

    expect(MailDomain::where('domain', $domain)->exists())->toBeFalse();
});

it('issues standalone mail.{domain} TLS and automatically syncs SNI via Phase 7 listener', function () {
    Queue::fake();

    $tenant = User::factory()->create([
        'username' => 'tenant_tls',
        'role' => 'user',
        'email' => 'tenant@standalone-tls.example.com',
    ]);

    $mailDomain = MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'standalone-tls.example.com',
        'ownership_source' => 'dns_txt',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 50,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('issueStandaloneMailTls')
        ->once()
        ->with('mail.standalone-tls.example.com', 'tenant@standalone-tls.example.com')
        ->andReturn([
            'domain' => 'mail.standalone-tls.example.com',
            'cert_path' => '/etc/letsencrypt/live/mail.standalone-tls.example.com/fullchain.pem',
            'key_path' => '/etc/letsencrypt/live/mail.standalone-tls.example.com/privkey.pem',
            'expires_at' => now()->addDays(90)->timestamp,
        ]);
    $this->app->instance(MailServerDriverInterface::class, $driver);

    $result = app(IssueStandaloneMailTlsAction::class)->execute($tenant, $mailDomain);

    expect($result['domain'])->toBe('mail.standalone-tls.example.com');

    Queue::assertPushed(CallQueuedListener::class, function ($job) {
        return $job->class === SyncMailSniOnSslIssuedListener::class;
    });
});

it('does not emit SslCertificateIssued when standalone TLS CLI issuance fails', function () {
    Event::fake([SslCertificateIssued::class]);

    $tenant = User::factory()->create([
        'username' => 'tenant_fail_tls',
        'role' => 'user',
    ]);
    $mailDomain = MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'fail-tls.example.com',
        'ownership_source' => 'dns_txt',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 50,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('issueStandaloneMailTls')
        ->once()
        ->andThrow(new LcCliException('ACME HTTP-01 challenge failed', 'lc-mail tls-issue'));
    $this->app->instance(MailServerDriverInterface::class, $driver);

    expect(fn () => app(IssueStandaloneMailTlsAction::class)->execute($tenant, $mailDomain))
        ->toThrow(LcCliException::class);

    Event::assertNotDispatched(SslCertificateIssued::class);
});

it('serves DNS TXT verification challenge and enables standalone domain via HTTP', function () {
    Queue::fake();

    $tenant = User::factory()->create([
        'username' => 'tenant_http',
        'role' => 'user',
    ]);
    $domain = 'http-standalone.acme-tenant.net';

    $challengeResponse = $this->actingAs($tenant)
        ->getJson(route('email.domains.dns-challenge', ['domain' => $domain]));

    $challengeResponse->assertOk()
        ->assertJson([
            'domain' => $domain,
            'record_type' => 'TXT',
            'record_name' => '_lc-mail-verify.'.$domain,
        ]);

    $tokenValue = $challengeResponse->json('value');

    $resolver = Mockery::mock(DnsTxtResolverInterface::class);
    $resolver->shouldReceive('resolveTxtRecords')
        ->once()
        ->with('_lc-mail-verify.'.$domain)
        ->andReturn([$tokenValue]);
    $this->app->instance(DnsTxtResolverInterface::class, $resolver);

    $this->actingAs($tenant)
        ->post(route('email.domains.store'), [
            'domain' => $domain,
            'verify_dns_txt' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_domains', [
        'user_id' => $tenant->id,
        'domain' => $domain,
        'ownership_source' => 'dns_txt',
    ]);
});
