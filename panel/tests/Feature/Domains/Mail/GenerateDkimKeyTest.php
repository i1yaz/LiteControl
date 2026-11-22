<?php

declare(strict_types=1);

use App\Domains\Dns\Listeners\SyncMailDnsRecordsOnChangedListener;
use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\GenerateDkimKeyAction;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\DkimSelector;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Exceptions\System\LcCliException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeDkimTestUser(string $username = 'dkimuser'): User
{
    return User::factory()->create([
        'username' => $username,
    ]);
}

it('generates a DKIM key, retires previous active key, dispatches reconcile and MailDnsRecordsChanged', function () {
    Queue::fake();
    Event::fake([MailDnsRecordsChanged::class]);

    $user = makeDkimTestUser('dkim1');
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'ownership_source' => 'webserver',
        'active' => true,
        'dkim_enabled' => false,
    ]);

    $oldKey = MailDkimKey::create([
        'mail_domain_id' => $mailDomain->id,
        'selector' => 'lc2025',
        'algorithm' => 'rsa2048',
        'public_key_txt' => 'v=DKIM1; k=rsa; p=OLDKEY',
        'private_key_path' => '/var/lib/rspamd/dkim/example.com.lc2025.key',
        'key_sha256' => str_repeat('a', 64),
        'status' => 'active',
        'created_at' => now()->subYear(),
    ]);

    $sha = str_repeat('b', 64);
    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('generateDkimKey')
        ->once()
        ->with('example.com', 'lc2026', 'rsa2048')
        ->andReturn([
            'public_key_txt' => 'v=DKIM1; k=rsa; p='.str_repeat('Z', 300),
            'private_key_path' => '/var/lib/rspamd/dkim/example.com.lc2026.key',
            'key_sha256' => $sha,
        ]);

    $this->app->instance(MailServerDriverInterface::class, $driver);

    $action = app(GenerateDkimKeyAction::class);
    $key = $action->execute($user, $mailDomain, new DkimSelector('lc2026'), 'rsa2048');

    expect($key->status)->toBe('active');
    expect($key->key_sha256)->toBe($sha);
    expect($key->private_key_path)->toBe('/var/lib/rspamd/dkim/example.com.lc2026.key');

    $oldKey->refresh();
    expect($oldKey->status)->toBe('retired');
    expect($oldKey->rotated_at)->not->toBeNull();

    $mailDomain->refresh();
    expect($mailDomain->dkim_enabled)->toBeTrue();

    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job) => $job->scope === 'dkim');
    Event::assertDispatched(MailDnsRecordsChanged::class, fn (MailDnsRecordsChanged $event) => $event->domain === 'example.com' && count($event->records) >= 8);
});

it('cleans up pending row when OS generateDkimKey fails', function () {
    $user = makeDkimTestUser('dkim2');
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'fail-os.com',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('generateDkimKey')
        ->once()
        ->andThrow(new LcCliException('rspamadm failed', 'lc-mail dkim-generate', [], []));

    $this->app->instance(MailServerDriverInterface::class, $driver);

    expect(fn () => app(GenerateDkimKeyAction::class)->execute($user, $mailDomain, new DkimSelector('lc2026')))
        ->toThrow(LcCliException::class);

    expect(MailDkimKey::where('mail_domain_id', $mailDomain->id)->count())->toBe(0);
});

it('runs compensating removeDkimKey when DB commit fails after OS generation', function () {
    $user = makeDkimTestUser('dkim3');
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'fail-db.com',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('generateDkimKey')
        ->once()
        ->andReturn([
            'public_key_txt' => 'v=DKIM1; k=rsa; p=TEST',
            'private_key_path' => '/var/lib/rspamd/dkim/fail-db.com.lc2026.key',
            'key_sha256' => str_repeat('c', 64),
        ]);
    $driver->shouldReceive('removeDkimKey')
        ->once()
        ->with('fail-db.com', 'lc2026')
        ->andReturn(['status' => 'success']);

    $auditLogger = Mockery::mock(AuditLoggerInterface::class);
    $auditLogger->shouldReceive('log')->once()->andThrow(new RuntimeException('DB audit failure'));

    $this->app->instance(MailServerDriverInterface::class, $driver);
    $this->app->instance(AuditLoggerInterface::class, $auditLogger);

    expect(fn () => app(GenerateDkimKeyAction::class)->execute($user, $mailDomain, new DkimSelector('lc2026')))
        ->toThrow(RuntimeException::class, 'DB audit failure');

    expect(MailDkimKey::where('mail_domain_id', $mailDomain->id)->count())->toBe(0);
});

it('rejects duplicate selector for the same mail domain', function () {
    $user = makeDkimTestUser('dkim4');
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'dup.com',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    MailDkimKey::create([
        'mail_domain_id' => $mailDomain->id,
        'selector' => 'lc2026',
        'algorithm' => 'rsa2048',
        'status' => 'active',
        'created_at' => now(),
    ]);

    expect(fn () => app(GenerateDkimKeyAction::class)->execute($user, $mailDomain, new DkimSelector('lc2026')))
        ->toThrow(ValidationException::class);
});

it('serves /email/dkim and authorizes domain ownership on POST', function () {
    Queue::fake();
    Event::fake([MailDnsRecordsChanged::class]);

    $owner = makeDkimTestUser('dkimowner');
    $other = makeDkimTestUser('dkimother');

    $mailDomain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'tenant.com',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->get('/email/dkim')
        ->assertOk();

    $this->actingAs($other)
        ->post('/email/dkim', [
            'mail_domain_id' => $mailDomain->id,
            'selector' => 'lc2026',
            'algorithm' => 'rsa2048',
        ])
        ->assertForbidden();

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('generateDkimKey')
        ->once()
        ->with('tenant.com', 'lc2026', 'rsa2048')
        ->andReturn([
            'public_key_txt' => 'v=DKIM1; k=rsa; p=TENANTKEY',
            'private_key_path' => '/var/lib/rspamd/dkim/tenant.com.lc2026.key',
            'key_sha256' => str_repeat('d', 64),
        ]);
    $this->app->instance(MailServerDriverInterface::class, $driver);

    $this->actingAs($owner)
        ->post('/email/dkim', [
            'mail_domain_id' => $mailDomain->id,
            'selector' => 'lc2026',
            'algorithm' => 'rsa2048',
        ])
        ->assertRedirect();

    expect(MailDkimKey::where('mail_domain_id', $mailDomain->id)->where('status', 'active')->count())->toBe(1);
});

it('only consumes MailDnsRecordsChanged in Dns listener when powerdns module is active', function () {
    Log::spy();

    $inactiveRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $inactiveRegistry->shouldReceive('isActive')->once()->with('dns')->andReturn(false);

    $listener = new SyncMailDnsRecordsOnChangedListener($inactiveRegistry);
    $listener->handle(new MailDnsRecordsChanged('example.com', [['type' => 'TXT']]));

    Log::shouldNotHaveReceived('info');

    $activeRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $activeRegistry->shouldReceive('isActive')->once()->with('dns')->andReturn(true);

    $activeListener = new SyncMailDnsRecordsOnChangedListener($activeRegistry);
    $activeListener->handle(new MailDnsRecordsChanged('example.com', [['type' => 'TXT']]));

    Log::shouldHaveReceived('info')->once();
});
