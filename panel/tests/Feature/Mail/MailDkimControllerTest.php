<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders Mail/Dkim/Index scoped to tenant, omits private_key_path, and returns required DNS records when powerdns is inactive', function (): void {
    $tenantA = User::factory()->create(['username' => 'dkimtenanta', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'dkimtenantb', 'role' => 'user']);

    $domainA = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'alpha.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'dkim_enabled' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $domainB = MailDomain::create([
        'user_id' => $tenantB->id,
        'domain' => 'beta.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'dkim_enabled' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $longPublicKey = 'v=DKIM1; k=rsa; p='.str_repeat('A', 300);

    MailDkimKey::create([
        'mail_domain_id' => $domainA->id,
        'selector' => 'lc202611',
        'algorithm' => 'rsa2048',
        'public_key_txt' => $longPublicKey,
        'private_key_path' => '/var/lib/rspamd/dkim/alpha.org.lc202611.key',
        'key_sha256' => hash('sha256', 'secret-a'),
        'status' => 'active',
        'created_at' => now(),
    ]);

    MailDkimKey::create([
        'mail_domain_id' => $domainB->id,
        'selector' => 'lc202611',
        'algorithm' => 'rsa2048',
        'public_key_txt' => 'v=DKIM1; k=rsa; p=SECRETB',
        'private_key_path' => '/var/lib/rspamd/dkim/beta.org.lc202611.key',
        'key_sha256' => hash('sha256', 'secret-b'),
        'status' => 'active',
        'created_at' => now(),
    ]);

    $mockRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $mockRegistry->shouldReceive('isActive')->with('powerdns')->andReturn(false);
    $mockRegistry->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $this->app->instance(ModuleRegistryQueryInterface::class, $mockRegistry);

    $this->actingAs($tenantA)
        ->get('/email/dkim?domain=alpha.org')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Dkim/Index')
            ->has('domains', 1)
            ->where('domains.0.domain', 'alpha.org')
            ->where('selected_domain.domain', 'alpha.org')
            ->where('powerdns_active', false)
            ->where('mail_core_active', true)
            ->has('dkim_keys', 1)
            ->where('dkim_keys.0.selector', 'lc202611')
            ->where('dkim_keys.0.algorithm', 'rsa2048')
            ->where('dkim_keys.0.status', 'active')
            ->missing('dkim_keys.0.private_key_path')
            ->has('dns_records', 9)
            ->where('dns_records.3.purpose', 'DKIM')
            ->where('dns_records.3.type', 'TXT')
            ->where('dns_records.3.host', 'lc202611._domainkey.alpha.org')
            ->has('dns_records.3.chunks', 2)
        );
});

it('exposes powerdns_active = true when powerdns module is active', function (): void {
    $tenant = User::factory()->create(['username' => 'dkimpdns', 'role' => 'user']);

    MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'pdns.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $mockRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $mockRegistry->shouldReceive('isActive')->with('powerdns')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $this->app->instance(ModuleRegistryQueryInterface::class, $mockRegistry);

    $this->actingAs($tenant)
        ->get('/email/dkim')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Dkim/Index')
            ->where('selected_domain.domain', 'pdns.org')
            ->where('powerdns_active', true)
            ->has('dns_records', 8)
        );
});

it('generates and rotates DKIM keys and blocks unauthorized tenants', function (): void {
    Queue::fake();

    $tenantA = User::factory()->create(['username' => 'dkimowner', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'dkimother', 'role' => 'user']);

    $domainA = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'rotate.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $mockDriver = Mockery::mock(MailServerDriverInterface::class);
    $mockDriver->shouldReceive('generateDkimKey')
        ->once()
        ->with('rotate.org', 'lc202611', 'rsa2048')
        ->andReturn([
            'public_key_txt' => 'v=DKIM1; k=rsa; p=NEWKEYDATA',
            'private_key_path' => '/var/lib/rspamd/dkim/rotate.org.lc202611.key',
            'key_sha256' => hash('sha256', 'new-key'),
        ]);
    $this->app->instance(MailServerDriverInterface::class, $mockDriver);

    $this->actingAs($tenantB)
        ->post('/email/dkim', [
            'mail_domain_id' => $domainA->id,
            'selector' => 'lc202611',
            'algorithm' => 'rsa2048',
        ])
        ->assertForbidden();

    $this->actingAs($tenantA)
        ->post('/email/dkim', [
            'mail_domain_id' => $domainA->id,
            'selector' => 'lc202611',
            'algorithm' => 'rsa2048',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_dkim_keys', [
        'mail_domain_id' => $domainA->id,
        'selector' => 'lc202611',
        'algorithm' => 'rsa2048',
        'status' => 'active',
    ]);
});
