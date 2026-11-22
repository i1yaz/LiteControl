<?php

declare(strict_types=1);

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\CreateMailAccountAction;
use App\Domains\Mail\Actions\CreateMailAliasAction;
use App\Domains\Mail\Actions\DeleteMailAccountAction;
use App\Domains\Mail\Actions\EnableMailDomainAction;
use App\Domains\Mail\Actions\UpdateMailAccountAction;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailboxJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Mail\ValueObjects\MailQuotaMb;
use App\Domains\Shared\Contracts\DomainOwnershipCheckerInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\ValueObjects\DomainName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeMailUser(array $attributes = []): User
{
    static $seq = 1;
    $id = $seq++;

    return User::factory()->create(array_merge([
        'username' => "mailuser{$id}",
        'role' => 'user',
    ], $attributes));
}

beforeEach(function (): void {
    Queue::fake();
    Event::fake([MailDnsRecordsChanged::class]);
});

it('enables a mail domain when owned, dispatches reconcile job and dns event, and blocks denylisted or unowned domains', function (): void {
    $user = makeMailUser(['role' => 'user']);
    $admin = makeMailUser(['role' => 'admin']);

    $checker = Mockery::mock(DomainOwnershipCheckerInterface::class);
    $checker->shouldReceive('userOwnsDomain')
        ->with($user->id, Mockery::on(fn (DomainName $d): bool => $d->value === 'acme.io'))
        ->andReturn(true);
    $checker->shouldReceive('userOwnsDomain')
        ->with($user->id, Mockery::on(fn (DomainName $d): bool => $d->value === 'stranger.io'))
        ->andReturn(false);
    $this->app->instance(DomainOwnershipCheckerInterface::class, $checker);

    $action = app(EnableMailDomainAction::class);

    // Denylist rejection
    expect(fn () => $action->execute($user, $user, new DomainName('gmail.com')))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->execute($user, $user, new DomainName('outlook.com')))
        ->toThrow(ValidationException::class);

    // Ownership guard
    expect(fn () => $action->execute($user, $user, new DomainName('stranger.io')))
        ->toThrow(ValidationException::class);

    // Owned domain succeeds
    $mailDomain = $action->execute($user, $user, new DomainName('acme.io'));
    expect($mailDomain->domain)->toBe('acme.io')
        ->and($mailDomain->ownership_source)->toBe('webserver');

    Queue::assertPushed(ReconcileMailServerJob::class);
    Event::assertDispatched(MailDnsRecordsChanged::class, fn (MailDnsRecordsChanged $e): bool => $e->domain === 'acme.io');

    // Admin override succeeds for external domain
    $external = $action->execute($admin, $user, new DomainName('external-mail.io'), adminOverride: true);
    expect($external->ownership_source)->toBe('admin_override');
});

it('blocks enabling a domain or creating an account when a pending purge tombstone exists', function (): void {
    $user = makeMailUser(['role' => 'user']);

    $checker = Mockery::mock(DomainOwnershipCheckerInterface::class);
    $checker->shouldReceive('userOwnsDomain')->andReturn(true);
    $this->app->instance(DomainOwnershipCheckerInterface::class, $checker);

    MailPendingPurge::create([
        'entity_type' => 'domain',
        'domain' => 'purging.io',
        'status' => 'pending',
    ]);

    expect(fn () => app(EnableMailDomainAction::class)->execute($user, $user, new DomainName('purging.io')))
        ->toThrow(ValidationException::class);

    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'live.io',
        'ownership_source' => 'webserver',
    ]);

    MailPendingPurge::create([
        'entity_type' => 'mailbox',
        'domain' => 'live.io',
        'local_part' => 'alice',
        'email' => 'alice@live.io',
        'status' => 'pending',
    ]);

    expect(fn () => app(CreateMailAccountAction::class)->execute(
        actor: $user,
        mailDomain: $mailDomain,
        email: new EmailAddress('alice@live.io'),
        password: 'SuperSecretPassword1!',
        quotaMb: new MailQuotaMb(512),
    ))->toThrow(ValidationException::class);
});

it('enforces package account/quota ceilings, hashes password with ARGON2ID, and blocks alias collision', function (): void {
    $package = Package::create([
        'name' => 'Starter',
        'disk_quota_mb' => 1000,
        'bandwidth_mb' => 10000,
        'max_domains' => 5,
        'max_subdomains' => 5,
        'max_databases' => 5,
        'max_ftp_accounts' => 5,
        'max_email_accounts' => 2,
    ]);
    $user = makeMailUser(['role' => 'user', 'package_id' => $package->id]);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'tenant.io',
        'ownership_source' => 'webserver',
    ]);

    MailAlias::create([
        'mail_domain_id' => $mailDomain->id,
        'source_email' => 'sales@tenant.io',
        'destination_email' => 'owner@external.io',
    ]);

    $createAccount = app(CreateMailAccountAction::class);

    // Collides with alias
    expect(fn () => $createAccount->execute(
        $user,
        $mailDomain,
        new EmailAddress('sales@tenant.io'),
        'Password123!',
        new MailQuotaMb(200),
    ))->toThrow(ValidationException::class);

    // Exceeds package quota ceiling
    expect(fn () => $createAccount->execute(
        $user,
        $mailDomain,
        new EmailAddress('a@tenant.io'),
        'Password123!',
        new MailQuotaMb(1500),
    ))->toThrow(ValidationException::class);

    $acc1 = $createAccount->execute($user, $mailDomain, new EmailAddress('a@tenant.io'), 'Password123!', new MailQuotaMb(400));
    expect($acc1->password_hash)->toStartWith('{ARGON2ID}$argon2id$');

    $createAccount->execute($user, $mailDomain, new EmailAddress('b@tenant.io'), 'Password123!', new MailQuotaMb(400));

    // Exceeds max_email_accounts (2)
    expect(fn () => $createAccount->execute(
        $user,
        $mailDomain,
        new EmailAddress('c@tenant.io'),
        'Password123!',
        new MailQuotaMb(100),
    ))->toThrow(ValidationException::class);
});

it('evicts sessions on password change, flag tightening, and account deletion, and cleans dependent aliases', function (): void {
    $user = makeMailUser(['role' => 'user']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'kick.io',
        'ownership_source' => 'webserver',
    ]);

    $account = app(CreateMailAccountAction::class)->execute(
        $user,
        $mailDomain,
        new EmailAddress('bob@kick.io'),
        'InitialPass123!',
        new MailQuotaMb(500),
    );

    MailAlias::create([
        'mail_domain_id' => $mailDomain->id,
        'source_email' => 'help@kick.io',
        'destination_email' => 'bob@kick.io',
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('evictSession')->with('bob@kick.io')->times(3)->andReturn([]);
    $this->app->instance(MailServerDriverInterface::class, $driver);

    $update = app(UpdateMailAccountAction::class);

    // Quota-only update should NOT evict session
    $update->execute($user, $account, quotaMb: new MailQuotaMb(600));

    // Password change -> evicts session (1)
    $update->execute($user, $account->fresh(), password: 'NewPassword456!');

    // Tightening active -> evicts session (2)
    $update->execute($user, $account->fresh(), active: false);

    // Deletion -> cleans alias, inserts tombstone, evicts session (3), dispatches PurgeMailboxJob
    $tombstone = app(DeleteMailAccountAction::class)->execute($user, $account->fresh());

    expect(MailAccount::find($account->id))->toBeNull()
        ->and(MailAlias::where('destination_email', 'bob@kick.io')->exists())->toBeFalse()
        ->and($tombstone->entity_type)->toBe('mailbox')
        ->and($tombstone->email)->toBe('bob@kick.io')
        ->and($tombstone->status)->toBe('pending');

    Queue::assertPushed(PurgeMailboxJob::class, fn (PurgeMailboxJob $job): bool => $job->purgeId === $tombstone->id && $job->localPart === 'bob');
});

it('enforces alias domain match, mailbox collision guard, catch-all support, and fan-out cap', function (): void {
    $user = makeMailUser(['role' => 'user']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'alias.io',
        'ownership_source' => 'webserver',
    ]);

    app(CreateMailAccountAction::class)->execute(
        $user,
        $mailDomain,
        new EmailAddress('inbox@alias.io'),
        'Password123!',
        new MailQuotaMb(256),
    );

    $createAlias = app(CreateMailAliasAction::class);

    // Domain mismatch
    expect(fn () => $createAlias->execute($user, $mailDomain, 'info@other.io', new EmailAddress('dest@dest.io')))
        ->toThrow(ValidationException::class);

    // Collides with existing mailbox
    expect(fn () => $createAlias->execute($user, $mailDomain, 'inbox@alias.io', new EmailAddress('dest@dest.io')))
        ->toThrow(ValidationException::class);

    // Catch-all succeeds
    $catchAll = $createAlias->execute($user, $mailDomain, '@alias.io', new EmailAddress('dest@dest.io'));
    expect($catchAll->is_catch_all)->toBeTrue();

    // Fan-out cap (5 destinations max)
    for ($i = 1; $i <= 5; $i++) {
        $createAlias->execute($user, $mailDomain, 'team@alias.io', new EmailAddress("member{$i}@dest.io"));
    }

    expect(fn () => $createAlias->execute($user, $mailDomain, 'team@alias.io', new EmailAddress('member6@dest.io')))
        ->toThrow(ValidationException::class);
});

it('enforces policies on HTTP routes for email accounts and forwarders', function (): void {
    $owner = makeMailUser(['role' => 'user']);
    $other = makeMailUser(['role' => 'user']);
    $mailDomain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'policy.io',
        'ownership_source' => 'webserver',
    ]);

    $account = app(CreateMailAccountAction::class)->execute(
        $owner,
        $mailDomain,
        new EmailAddress('user@policy.io'),
        'Password123!',
        new MailQuotaMb(256),
    );

    $alias = app(CreateMailAliasAction::class)->execute(
        $owner,
        $mailDomain,
        'info@policy.io',
        new EmailAddress('dest@dest.io'),
    );

    $this->actingAs($owner)->get('/email/accounts')->assertOk();
    $this->actingAs($owner)->get('/email/forwarders')->assertOk();

    // Other tenant cannot update or delete owner's account or alias
    $this->actingAs($other)->put("/email/accounts/{$account->id}", ['active' => false])->assertForbidden();
    $this->actingAs($other)->delete("/email/accounts/{$account->id}")->assertForbidden();
    $this->actingAs($other)->delete("/email/forwarders/{$alias->id}")->assertForbidden();
});
