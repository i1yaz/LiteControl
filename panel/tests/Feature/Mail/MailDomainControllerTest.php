<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([MailDnsRecordsChanged::class]);
});

it('scopes mail domains to the authenticated tenant and exposes required props', function () {
    $tenantA = User::factory()->create(['username' => 'tenanta', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'tenantb', 'role' => 'user']);

    MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'alpha.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
        'outbound_hourly_limit' => 200,
    ]);

    MailDomain::create([
        'user_id' => $tenantB->id,
        'domain' => 'beta.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 512,
        'max_accounts' => 10,
        'outbound_hourly_limit' => 100,
    ]);

    Domain::create([
        'user_id' => $tenantA->id,
        'domain' => 'available-a.org',
        'type' => 'primary',
        'document_root' => '/home/a/available-a.org',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    Domain::create([
        'user_id' => $tenantB->id,
        'domain' => 'available-b.org',
        'type' => 'primary',
        'document_root' => '/home/b/available-b.org',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    $this->actingAs($tenantA)
        ->get('/email/domains')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Domains/Index')
            ->where('can_admin_override', false)
            ->has('domains', 1)
            ->where('domains.0.domain', 'alpha.org')
            ->where('domains.0.outbound_hourly_limit', 200)
            ->where('available_web_domains', ['available-a.org'])
            ->has('pending_purges', 0)
        );
});

it('shows admin override capability and all domains for admins', function () {
    $admin = User::factory()->create(['username' => 'admin1', 'role' => 'admin']);
    $tenant = User::factory()->create(['username' => 'tenant1', 'role' => 'user']);

    MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'tenant-domain.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 50,
    ]);

    $this->actingAs($admin)
        ->get('/email/domains')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Domains/Index')
            ->where('can_admin_override', true)
            ->has('domains', 1)
            ->where('domains.0.domain', 'tenant-domain.org')
        );
});

it('allows admin to enable a mail domain via admin override with custom outbound limit', function () {
    $admin = User::factory()->create(['username' => 'admin2', 'role' => 'admin']);

    $this->actingAs($admin)
        ->post('/email/domains', [
            'domain' => 'external-mail.org',
            'admin_override' => true,
            'default_quota_mb' => 2048,
            'max_accounts' => 100,
            'outbound_hourly_limit' => 500,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_domains', [
        'domain' => 'external-mail.org',
        'ownership_source' => 'admin_override',
        'default_quota_mb' => 2048,
        'max_accounts' => 100,
        'outbound_hourly_limit' => 500,
    ]);

    Queue::assertPushed(ReconcileMailServerJob::class);
});

it('rejects reserved and public-suffix domains on the denylist', function () {
    $admin = User::factory()->create(['username' => 'admin3', 'role' => 'admin']);

    $this->actingAs($admin)
        ->post('/email/domains', [
            'domain' => 'gmail.com',
            'admin_override' => true,
        ])
        ->assertSessionHasErrors('domain');

    $this->assertDatabaseMissing('mail_domains', ['domain' => 'gmail.com']);
});

it('blocks enabling a domain that has an active pending purge tombstone', function () {
    $admin = User::factory()->create(['username' => 'admin4', 'role' => 'admin']);

    MailPendingPurge::create([
        'entity_type' => 'domain',
        'domain' => 'purging-domain.org',
        'status' => 'pending',
        'attempts' => 1,
    ]);

    $this->actingAs($admin)
        ->post('/email/domains', [
            'domain' => 'purging-domain.org',
            'admin_override' => true,
        ])
        ->assertSessionHasErrors('domain');

    $this->assertDatabaseMissing('mail_domains', ['domain' => 'purging-domain.org']);
});

it('allows owner to update domain defaults and forbids other tenants', function () {
    $tenantA = User::factory()->create(['username' => 'owner1', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'other1', 'role' => 'user']);

    $mailDomain = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'owner-domain.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 50,
        'outbound_hourly_limit' => 200,
    ]);

    $this->actingAs($tenantB)
        ->put("/email/domains/{$mailDomain->id}", [
            'default_quota_mb' => 512,
            'max_accounts' => 10,
            'outbound_hourly_limit' => 50,
            'active' => false,
        ])
        ->assertForbidden();

    $this->actingAs($tenantA)
        ->put("/email/domains/{$mailDomain->id}", [
            'default_quota_mb' => 4096,
            'max_accounts' => 75,
            'outbound_hourly_limit' => 350,
            'active' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_domains', [
        'id' => $mailDomain->id,
        'default_quota_mb' => 4096,
        'max_accounts' => 75,
        'outbound_hourly_limit' => 350,
        'active' => false,
    ]);
});
