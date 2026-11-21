<?php

declare(strict_types=1);

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\CreateMailAccountAction;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailboxJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('scopes mailboxes to the authenticated tenant, filters by domain, and never exposes password_hash', function (): void {
    $package = Package::create([
        'name' => 'Starter Mail',
        'max_domains' => 5,
        'max_databases' => 5,
        'max_email_accounts' => 10,
        'disk_quota_mb' => 4096,
        'bandwidth_gb' => 100,
    ]);

    $tenantA = User::factory()->create([
        'username' => 'mailtenanta',
        'role' => 'user',
        'package_id' => $package->id,
    ]);
    $tenantB = User::factory()->create([
        'username' => 'mailtenantb',
        'role' => 'user',
    ]);

    $domainA1 = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'alpha.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $domainA2 = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'gamma.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 512,
        'max_accounts' => 10,
    ]);

    $domainB = MailDomain::create([
        'user_id' => $tenantB->id,
        'domain' => 'beta.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    MailAccount::create([
        'mail_domain_id' => $domainA1->id,
        'local_part' => 'alice',
        'email' => 'alice@alpha.org',
        'password_hash' => CreateMailAccountAction::hashPassword('SecretPass123!'),
        'quota_mb' => 1024,
        'used_bytes' => 1048576,
        'active' => true,
        'allow_imap' => true,
        'allow_pop3' => false,
        'allow_smtp' => true,
    ]);

    MailAccount::create([
        'mail_domain_id' => $domainA2->id,
        'local_part' => 'bob',
        'email' => 'bob@gamma.org',
        'password_hash' => CreateMailAccountAction::hashPassword('SecretPass123!'),
        'quota_mb' => 512,
        'used_bytes' => 0,
        'active' => true,
        'allow_imap' => true,
        'allow_pop3' => false,
        'allow_smtp' => true,
    ]);

    MailAccount::create([
        'mail_domain_id' => $domainB->id,
        'local_part' => 'eve',
        'email' => 'eve@beta.org',
        'password_hash' => CreateMailAccountAction::hashPassword('SecretPass123!'),
        'quota_mb' => 1024,
        'used_bytes' => 0,
        'active' => true,
        'allow_imap' => true,
        'allow_pop3' => false,
        'allow_smtp' => true,
    ]);

    MailPendingPurge::create([
        'entity_type' => 'mailbox',
        'domain' => 'alpha.org',
        'local_part' => 'old',
        'email' => 'old@alpha.org',
        'status' => 'pending',
        'attempts' => 1,
    ]);

    $this->actingAs($tenantA)
        ->get('/email/accounts?domain=alpha.org')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Accounts/Index')
            ->has('domains', 2)
            ->where('selected_domain.domain', 'alpha.org')
            ->has('accounts.data', 1)
            ->where('accounts.data.0.email', 'alice@alpha.org')
            ->missing('accounts.data.0.password_hash')
            ->where('package_limits.used_accounts', 2)
            ->where('package_limits.max_accounts', 10)
            ->where('package_limits.max_quota_mb', 4096)
            ->has('pending_purges', 1)
            ->where('pending_purges.0.email', 'old@alpha.org')
        );
});

it('creates, updates, and deletes a mailbox and enforces tenant policy boundaries', function (): void {
    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('evictSession')->with('info@owner.org')->twice();
    $this->app->instance(MailServerDriverInterface::class, $driver);

    $owner = User::factory()->create(['username' => 'mbowner', 'role' => 'user']);
    $other = User::factory()->create(['username' => 'mbother', 'role' => 'user']);

    $domain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'owner.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    // Other tenant cannot create on owner's domain
    $this->actingAs($other)
        ->post('/email/accounts', [
            'mail_domain_id' => $domain->id,
            'local_part' => 'info',
            'password' => 'StrongPass123!',
            'quota_mb' => 512,
        ])
        ->assertForbidden();

    // Owner creates mailbox
    $this->actingAs($owner)
        ->post('/email/accounts', [
            'mail_domain_id' => $domain->id,
            'local_part' => 'info',
            'password' => 'StrongPass123!',
            'quota_mb' => 512,
            'allow_imap' => true,
            'allow_pop3' => false,
            'allow_smtp' => true,
        ])
        ->assertRedirect();

    $account = MailAccount::where('email', 'info@owner.org')->firstOrFail();
    expect($account->allow_pop3)->toBeFalse();

    // Other tenant cannot update or delete
    $this->actingAs($other)
        ->put("/email/accounts/{$account->id}", ['active' => false])
        ->assertForbidden();

    $this->actingAs($other)
        ->delete("/email/accounts/{$account->id}")
        ->assertForbidden();

    // Owner updates mailbox (triggers evictSession)
    $this->actingAs($owner)
        ->put("/email/accounts/{$account->id}", [
            'quota_mb' => 2048,
            'active' => false,
            'allow_imap' => true,
            'allow_pop3' => true,
            'allow_smtp' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_accounts', [
        'id' => $account->id,
        'quota_mb' => 2048,
        'active' => false,
        'allow_pop3' => true,
        'allow_smtp' => false,
    ]);

    // Owner deletes mailbox (triggers evictSession + PurgeMailboxJob)
    $this->actingAs($owner)
        ->delete("/email/accounts/{$account->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
    $this->assertDatabaseHas('mail_pending_purges', [
        'entity_type' => 'mailbox',
        'email' => 'info@owner.org',
        'status' => 'pending',
    ]);
    Queue::assertPushed(PurgeMailboxJob::class);
});
