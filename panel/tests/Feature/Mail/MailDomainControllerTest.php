<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\DnsTxtResolverInterface;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailDomainJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Mail\ValueObjects\DnsTxtVerificationToken;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\Events\SslCertificateIssued;
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

it('generates a deterministic DNS TXT verification challenge for a valid domain', function () {
    $tenant = User::factory()->create(['username' => 'txtuser1', 'role' => 'user']);

    $expectedToken = DnsTxtVerificationToken::forUserAndDomain(
        $tenant->id,
        'standalone-mail.org',
        (string) config('app.key'),
    );

    $this->actingAs($tenant)
        ->getJson('/email/domains/dns-challenge?domain=standalone-mail.org')
        ->assertOk()
        ->assertJson([
            'domain' => 'standalone-mail.org',
            'record_type' => 'TXT',
            'record_name' => '_lc-mail-verify.standalone-mail.org',
            'value' => $expectedToken->value,
        ]);

    $this->actingAs($tenant)
        ->getJson('/email/domains/dns-challenge?domain=invalid_domain')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('domain');
});

it('fails with actionable error when DNS TXT verification record is missing', function () {
    $tenant = User::factory()->create(['username' => 'txtuser2', 'role' => 'user']);

    $this->app->bind(DnsTxtResolverInterface::class, fn () => new class implements DnsTxtResolverInterface
    {
        public function resolveTxtRecords(string $recordName): array
        {
            return [];
        }
    });

    $this->actingAs($tenant)
        ->post('/email/domains', [
            'domain' => 'unverified-mail.org',
            'verify_dns_txt' => true,
        ])
        ->assertSessionHasErrors('domain');

    $this->assertDatabaseMissing('mail_domains', ['domain' => 'unverified-mail.org']);
});

it('creates a standalone dns_txt mail domain when DNS TXT verification succeeds and exposes SNI status', function () {
    $tenant = User::factory()->create(['username' => 'txtuser3', 'role' => 'user']);

    $expectedToken = DnsTxtVerificationToken::forUserAndDomain(
        $tenant->id,
        'verified-mail.org',
        (string) config('app.key'),
    );

    $this->app->bind(DnsTxtResolverInterface::class, fn () => new class($expectedToken->value) implements DnsTxtResolverInterface
    {
        public function __construct(private readonly string $token) {}

        public function resolveTxtRecords(string $recordName): array
        {
            return $recordName === '_lc-mail-verify.verified-mail.org' ? [$this->token] : [];
        }
    });

    $this->actingAs($tenant)
        ->post('/email/domains', [
            'domain' => 'verified-mail.org',
            'verify_dns_txt' => true,
            'default_quota_mb' => 2048,
            'max_accounts' => 20,
            'outbound_hourly_limit' => 150,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mail_domains', [
        'user_id' => $tenant->id,
        'domain' => 'verified-mail.org',
        'ownership_source' => 'dns_txt',
        'default_quota_mb' => 2048,
        'max_accounts' => 20,
        'outbound_hourly_limit' => 150,
    ]);

    $this->actingAs($tenant)
        ->get('/email/domains')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Domains/Index')
            ->has('domains', 1)
            ->where('domains.0.domain', 'verified-mail.org')
            ->where('domains.0.ownership_source', 'dns_txt')
            ->where('domains.0.tls_sni_synced_at', null)
        );
});

it('issues standalone HTTP-01 TLS for a dns_txt domain owner and forbids other tenants', function () {
    Event::fake([SslCertificateIssued::class]);

    $owner = User::factory()->create(['username' => 'tlsowner', 'role' => 'user']);
    $other = User::factory()->create(['username' => 'tlsother', 'role' => 'user']);

    $mailDomain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'tls-standalone.org',
        'ownership_source' => 'dns_txt',
        'active' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
        'outbound_hourly_limit' => 200,
    ]);

    $driverMock = Mockery::mock(MailServerDriverInterface::class);
    $driverMock->shouldReceive('issueStandaloneMailTls')
        ->once()
        ->with('tls-standalone.org', $owner->email)
        ->andReturn([
            'domain' => 'mail.tls-standalone.org',
            'cert_path' => '/etc/letsencrypt/live/mail.tls-standalone.org/fullchain.pem',
            'key_path' => '/etc/letsencrypt/live/mail.tls-standalone.org/privkey.pem',
            'expires_at' => now()->addDays(90)->timestamp,
        ]);
    $this->app->instance(MailServerDriverInterface::class, $driverMock);

    $this->actingAs($other)
        ->post("/email/domains/{$mailDomain->id}/issue-tls")
        ->assertForbidden();

    $this->actingAs($owner)
        ->post("/email/domains/{$mailDomain->id}/issue-tls")
        ->assertRedirect()
        ->assertSessionHas('success');

    Event::assertDispatched(SslCertificateIssued::class);
});

it('deletes a mail domain with its accounts, aliases, DKIM keys, and dispatches purge + reconcile jobs', function () {
    $owner = User::factory()->create(['username' => 'delowner', 'role' => 'user']);
    $other = User::factory()->create(['username' => 'delother', 'role' => 'user']);

    $mailDomain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'delete-me.org',
        'ownership_source' => 'dns_txt',
        'active' => true,
        'dkim_enabled' => true,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
        'outbound_hourly_limit' => 200,
    ]);

    $account = MailAccount::create([
        'mail_domain_id' => $mailDomain->id,
        'user_id' => $owner->id,
        'local_part' => 'info',
        'email' => 'info@delete-me.org',
        'password_hash' => '{BLF-CRYPT}$2y$10$dummyhash',
        'quota_mb' => 1024,
        'active' => true,
    ]);

    $alias = MailAlias::create([
        'mail_domain_id' => $mailDomain->id,
        'user_id' => $owner->id,
        'source_local_part' => 'sales',
        'source_email' => 'sales@delete-me.org',
        'destination_email' => 'info@delete-me.org',
        'active' => true,
    ]);

    $dkim = MailDkimKey::create([
        'mail_domain_id' => $mailDomain->id,
        'selector' => 'lc202611',
        'algorithm' => 'rsa2048',
        'public_key_txt' => 'v=DKIM1; k=rsa; p=TEST',
        'private_key_path' => '/var/lib/rspamd/dkim/delete-me.org.lc202611.key',
        'key_sha256' => hash('sha256', 'test'),
        'status' => 'active',
        'created_at' => now(),
    ]);

    $driverMock = Mockery::mock(MailServerDriverInterface::class);
    $driverMock->shouldReceive('evictSession')->once()->with('info@delete-me.org')->andReturn([]);
    $this->app->instance(MailServerDriverInterface::class, $driverMock);

    // Other tenant is forbidden
    $this->actingAs($other)
        ->delete("/email/domains/{$mailDomain->id}", ['delete_ssl' => true])
        ->assertForbidden();

    $this->assertDatabaseHas('mail_domains', ['id' => $mailDomain->id]);

    // Owner deletes domain
    $this->actingAs($owner)
        ->delete("/email/domains/{$mailDomain->id}", ['delete_ssl' => true])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('mail_domains', ['id' => $mailDomain->id]);
    $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
    $this->assertDatabaseMissing('mail_aliases', ['id' => $alias->id]);
    $this->assertDatabaseMissing('mail_dkim_keys', ['id' => $dkim->id]);
    $this->assertDatabaseHas('mail_pending_purges', [
        'entity_type' => 'domain',
        'domain' => 'delete-me.org',
        'status' => 'pending',
    ]);

    Queue::assertPushed(PurgeMailDomainJob::class, fn (PurgeMailDomainJob $job): bool => $job->domain === 'delete-me.org' && $job->deleteSsl === true);
    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job): bool => $job->scope === 'all');
    Event::assertDispatched(MailDnsRecordsChanged::class, fn (MailDnsRecordsChanged $event): bool => $event->domain === 'delete-me.org');
});

it('purges domain maildir and standalone SSL certificate when PurgeMailDomainJob runs with deleteSsl=true', function () {
    $tombstone = MailPendingPurge::create([
        'entity_type' => 'domain',
        'domain' => 'ssl-purge.org',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $driverMock = Mockery::mock(MailServerDriverInterface::class);
    $driverMock->shouldReceive('purgeDomain')->once()->with('ssl-purge.org')->andReturn([]);
    $driverMock->shouldReceive('removeStandaloneMailTls')->once()->with('ssl-purge.org')->andReturn(['removed' => true]);

    $job = new PurgeMailDomainJob($tombstone->id, 'ssl-purge.org', deleteSsl: true);
    $job->handle($driverMock);

    $this->assertDatabaseMissing('mail_pending_purges', ['id' => $tombstone->id]);
});
