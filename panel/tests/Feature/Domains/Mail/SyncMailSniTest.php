<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Listeners\SyncMailSniOnSslIssuedListener;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\SslCertificateIssued;
use App\Domains\WebServer\Actions\InstallCustomSslAction;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Jobs\IssueSslJob;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('configures SyncMailSniOnSslIssuedListener as queued and afterCommit', function () {
    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $listener = new SyncMailSniOnSslIssuedListener($registry);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->afterCommit)->toBeTrue();
});

it('updates tls_sni_synced_at and dispatches debounced sni reconcile when ssl is issued for an active mail domain', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $user = User::factory()->create(['username' => 'sniuser']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'example.test',
        'ownership_source' => 'webserver',
        'active' => true,
        'tls_sni_synced_at' => null,
    ]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(true);

    $listener = new SyncMailSniOnSslIssuedListener($registry);
    $listener->handle(new SslCertificateIssued(
        domain: 'example.test',
        certPath: '/etc/letsencrypt/live/example.test/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/example.test/privkey.pem',
        expiresAt: now()->addDays(90)->timestamp,
    ));

    expect($mailDomain->fresh()->tls_sni_synced_at)->not->toBeNull();

    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job): bool => $job->scope === 'sni');
});

it('matches base mail domain when ssl certificate is issued for mail subdomain', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $user = User::factory()->create(['username' => 'snisubuser']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'tenant.test',
        'ownership_source' => 'webserver',
        'active' => true,
        'tls_sni_synced_at' => null,
    ]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(true);

    $listener = new SyncMailSniOnSslIssuedListener($registry);
    $listener->handle(new SslCertificateIssued(
        domain: 'mail.tenant.test',
        certPath: '/etc/letsencrypt/live/mail.tenant.test/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/mail.tenant.test/privkey.pem',
        expiresAt: now()->addDays(90)->timestamp,
    ));

    expect($mailDomain->fresh()->tls_sni_synced_at)->not->toBeNull();

    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job): bool => $job->scope === 'sni');
});

it('skips sni reconcile when mail_core is inactive', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $user = User::factory()->create(['username' => 'sniinactive']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'inactive-core.test',
        'ownership_source' => 'webserver',
        'active' => true,
        'tls_sni_synced_at' => null,
    ]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(false);

    $listener = new SyncMailSniOnSslIssuedListener($registry);
    $listener->handle(new SslCertificateIssued(
        domain: 'inactive-core.test',
        certPath: '/etc/letsencrypt/live/inactive-core.test/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/inactive-core.test/privkey.pem',
        expiresAt: now()->addDays(90)->timestamp,
    ));

    expect($mailDomain->fresh()->tls_sni_synced_at)->toBeNull();
    Queue::assertNotPushed(ReconcileMailServerJob::class);
});

it('skips sni reconcile when domain has no active mail domain record', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $user = User::factory()->create(['username' => 'sninomail']);
    $inactiveDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'disabled.test',
        'ownership_source' => 'webserver',
        'active' => false,
        'tls_sni_synced_at' => null,
    ]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->twice()->with('mail_core')->andReturn(true);

    $listener = new SyncMailSniOnSslIssuedListener($registry);
    $listener->handle(new SslCertificateIssued(
        domain: 'disabled.test',
        certPath: '/etc/letsencrypt/live/disabled.test/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/disabled.test/privkey.pem',
        expiresAt: now()->addDays(90)->timestamp,
    ));

    $listener->handle(new SslCertificateIssued(
        domain: 'webonly.test',
        certPath: '/etc/letsencrypt/live/webonly.test/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/webonly.test/privkey.pem',
        expiresAt: now()->addDays(90)->timestamp,
    ));

    expect($inactiveDomain->fresh()->tls_sni_synced_at)->toBeNull();
    Queue::assertNotPushed(ReconcileMailServerJob::class);
});

it('dispatches SslCertificateIssued when IssueSslJob succeeds', function () {
    Event::fake([SslCertificateIssued::class]);

    $user = User::factory()->create(['username' => 'ssljobuser']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'issued.test',
        'document_root' => '/home/ssljobuser/public_html',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    $sslDriver = Mockery::mock(SslDriverInterface::class);
    $sslDriver->shouldReceive('issue')
        ->once()
        ->with('issued.test', '/home/ssljobuser/public_html', 'ssl@issued.test')
        ->andReturn([]);

    $job = new IssueSslJob($domain, 'ssl@issued.test', false);
    $job->handle($sslDriver);

    Event::assertDispatched(SslCertificateIssued::class, function (SslCertificateIssued $event): bool {
        return $event->domain === 'issued.test'
            && $event->certPath === '/etc/letsencrypt/live/issued.test/fullchain.pem'
            && $event->keyPath === '/etc/letsencrypt/live/issued.test/privkey.pem'
            && $event->expiresAt > now()->timestamp;
    });
});

it('dispatches SslCertificateIssued when InstallCustomSslAction succeeds', function () {
    Event::fake([SslCertificateIssued::class]);

    $user = User::factory()->create(['username' => 'customssluser']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'custom.test',
        'document_root' => '/home/customssluser/public_html',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    $sslDriver = Mockery::mock(SslDriverInterface::class);
    $sslDriver->shouldReceive('isMockMode')->andReturn(true);
    $sslDriver->shouldReceive('installCustom')->once()->andReturn([
        'cert_path' => '/etc/letsencrypt/live/custom.test/fullchain.pem',
        'key_path' => '/etc/letsencrypt/live/custom.test/privkey.pem',
    ]);

    $action = new InstallCustomSslAction($sslDriver);
    $result = $action->handle($domain, 'CERT-PEM', 'KEY-PEM', $user);

    expect($result['status'])->toBe('success');

    Event::assertDispatched(SslCertificateIssued::class, function (SslCertificateIssued $event): bool {
        return $event->domain === 'custom.test'
            && $event->certPath === '/etc/letsencrypt/live/custom.test/fullchain.pem'
            && $event->keyPath === '/etc/letsencrypt/live/custom.test/privkey.pem';
    });
});
