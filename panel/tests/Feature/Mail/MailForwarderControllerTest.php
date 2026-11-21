<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\CreateMailAccountAction;
use App\Domains\Mail\Actions\CreateMailAliasAction;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('scopes forwarders to the authenticated tenant, filters by domain and type, and exposes fanout props', function (): void {
    $tenantA = User::factory()->create(['username' => 'aliastenanta', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'aliastenantb', 'role' => 'user']);

    $domainA = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'alpha.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $domainB = MailDomain::create([
        'user_id' => $tenantB->id,
        'domain' => 'beta.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    MailAlias::create([
        'mail_domain_id' => $domainA->id,
        'source_email' => 'sales@alpha.org',
        'destination_email' => 'one@dest.org',
        'active' => true,
    ]);

    MailAlias::create([
        'mail_domain_id' => $domainA->id,
        'source_email' => 'sales@alpha.org',
        'destination_email' => 'two@dest.org',
        'active' => true,
    ]);

    MailAlias::create([
        'mail_domain_id' => $domainA->id,
        'source_email' => '@alpha.org',
        'destination_email' => 'catchall@dest.org',
        'active' => true,
    ]);

    MailAlias::create([
        'mail_domain_id' => $domainB->id,
        'source_email' => 'secret@beta.org',
        'destination_email' => 'other@dest.org',
        'active' => true,
    ]);

    $this->actingAs($tenantA)
        ->get('/email/forwarders?domain=alpha.org&type=catch_all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Forwarders/Index')
            ->has('domains', 1)
            ->where('selected_domain.domain', 'alpha.org')
            ->where('fanout_limit', CreateMailAliasAction::DEFAULT_MAX_FAN_OUT)
            ->where('type_filter', 'catch_all')
            ->where('source_counts', [
                '@alpha.org' => 1,
                'sales@alpha.org' => 2,
            ])
            ->has('aliases.data', 1)
            ->where('aliases.data.0.source_email', '@alpha.org')
            ->where('aliases.data.0.is_catch_all', true)
        );
});

it('creates address forwarders and domain catch-alls', function (): void {
    $tenant = User::factory()->create(['username' => 'aliascreator', 'role' => 'user']);
    $domain = MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'example.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $this->actingAs($tenant)
        ->post('/email/forwarders', [
            'mail_domain_id' => $domain->id,
            'source_email' => 'sales@example.org',
            'destination_email' => 'team@external.org',
        ])
        ->assertRedirect();

    $this->actingAs($tenant)
        ->post('/email/forwarders', [
            'mail_domain_id' => $domain->id,
            'source_email' => '@example.org',
            'destination_email' => 'inbox@external.org',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_aliases', [
        'mail_domain_id' => $domain->id,
        'source_email' => 'sales@example.org',
        'destination_email' => 'team@external.org',
        'is_catch_all' => false,
    ]);

    $this->assertDatabaseHas('mail_aliases', [
        'mail_domain_id' => $domain->id,
        'source_email' => '@example.org',
        'destination_email' => 'inbox@external.org',
        'is_catch_all' => true,
    ]);
});

it('returns validation errors for mailbox collisions and fan-out cap breaches', function (): void {
    $tenant = User::factory()->create(['username' => 'aliaslimits', 'role' => 'user']);
    $domain = MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'limits.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    MailAccount::create([
        'mail_domain_id' => $domain->id,
        'local_part' => 'alice',
        'email' => 'alice@limits.org',
        'password_hash' => CreateMailAccountAction::hashPassword('SecretPass123!'),
        'quota_mb' => 1024,
        'used_bytes' => 0,
        'active' => true,
        'allow_imap' => true,
        'allow_pop3' => false,
        'allow_smtp' => true,
    ]);

    // Collision with existing mailbox
    $this->actingAs($tenant)
        ->post('/email/forwarders', [
            'mail_domain_id' => $domain->id,
            'source_email' => 'alice@limits.org',
            'destination_email' => 'outside@dest.org',
        ])
        ->assertSessionHasErrors('source_email');

    // Fill fan-out cap (5 destinations)
    for ($i = 1; $i <= CreateMailAliasAction::DEFAULT_MAX_FAN_OUT; $i++) {
        MailAlias::create([
            'mail_domain_id' => $domain->id,
            'source_email' => 'team@limits.org',
            'destination_email' => "member{$i}@dest.org",
            'active' => true,
        ]);
    }

    // 6th destination should fail
    $this->actingAs($tenant)
        ->post('/email/forwarders', [
            'mail_domain_id' => $domain->id,
            'source_email' => 'team@limits.org',
            'destination_email' => 'member6@dest.org',
        ])
        ->assertSessionHasErrors('destination_email');
});

it('toggles active status, deletes forwarders, and enforces tenant authorization', function (): void {
    $tenantA = User::factory()->create(['username' => 'aliasownera', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'aliasownerb', 'role' => 'user']);

    $domainA = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'ownera.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $aliasA = MailAlias::create([
        'mail_domain_id' => $domainA->id,
        'source_email' => 'info@ownera.org',
        'destination_email' => 'dest@external.org',
        'active' => true,
    ]);

    // Tenant B cannot create on Tenant A's domain, update, or delete Tenant A's alias
    $this->actingAs($tenantB)
        ->post('/email/forwarders', [
            'mail_domain_id' => $domainA->id,
            'source_email' => 'hack@ownera.org',
            'destination_email' => 'evil@external.org',
        ])
        ->assertForbidden();

    $this->actingAs($tenantB)
        ->put("/email/forwarders/{$aliasA->id}", ['active' => false])
        ->assertForbidden();

    $this->actingAs($tenantB)
        ->delete("/email/forwarders/{$aliasA->id}")
        ->assertForbidden();

    // Tenant A can toggle active and delete
    $this->actingAs($tenantA)
        ->put("/email/forwarders/{$aliasA->id}", ['active' => false])
        ->assertRedirect();

    expect($aliasA->fresh()->active)->toBeFalse();

    $this->actingAs($tenantA)
        ->delete("/email/forwarders/{$aliasA->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('mail_aliases', ['id' => $aliasA->id]);
});
