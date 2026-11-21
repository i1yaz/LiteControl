<?php

declare(strict_types=1);

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Jobs\CreateBackupJob;
use App\Domains\Backup\Jobs\RestoreBackupJob;
use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Actions\DeleteUserAction;
use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailboxJob;
use App\Domains\Mail\Jobs\PurgeMailDomainJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Jobs\SyncMailboxQuotasJob;
use App\Domains\Mail\Listeners\ReconcileMailOnBackupRestoredListener;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\BackupRestored;
use App\Domains\Shared\ValueObjects\SystemPath;
use App\Exceptions\System\LcCliException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('configures ReconcileMailServerJob as unique, queued, and afterCommit', function () {
    $job = new ReconcileMailServerJob('sni');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->uniqueId())->toBe('mail_reconcile_sni');

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('reconcile')->once()->with('sni')->andReturn(['reloaded' => ['postfix']]);

    $job->handle($driver);
});

it('purges mailbox on disk and clears tombstone only after success', function () {
    $tombstone = MailPendingPurge::create([
        'entity_type' => 'mailbox',
        'domain' => 'example.test',
        'local_part' => 'alice',
        'email' => 'alice@example.test',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('purgeMailbox')->once()->with('example.test', 'alice')->andReturn(['purged' => true]);

    $job = new PurgeMailboxJob($tombstone->id, 'example.test', 'alice');
    $job->handle($driver);

    expect(MailPendingPurge::find($tombstone->id))->toBeNull();
});

it('retains mailbox tombstone and marks failed when disk purge throws LcCliException', function () {
    $tombstone = MailPendingPurge::create([
        'entity_type' => 'mailbox',
        'domain' => 'example.test',
        'local_part' => 'alice',
        'email' => 'alice@example.test',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('purgeMailbox')
        ->once()
        ->with('example.test', 'alice')
        ->andThrow(new LcCliException('Permission denied', 'lc-mail purge-mailbox', []));

    $job = new PurgeMailboxJob($tombstone->id, 'example.test', 'alice');

    expect(fn () => $job->handle($driver))->toThrow(LcCliException::class);

    $tombstone->refresh();
    expect($tombstone->status)->toBe('failed')
        ->and($tombstone->attempts)->toBe(1)
        ->and($tombstone->last_error)->toContain('Permission denied');
});

it('purges mail domain on disk and clears tombstone only after success', function () {
    $tombstone = MailPendingPurge::create([
        'entity_type' => 'domain',
        'domain' => 'example.test',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('purgeDomain')->once()->with('example.test')->andReturn(['purged' => true]);

    $job = new PurgeMailDomainJob($tombstone->id, 'example.test');
    expect($job->afterCommit)->toBeTrue();

    $job->handle($driver);

    expect(MailPendingPurge::find($tombstone->id))->toBeNull();
});

it('retains mail domain tombstone and marks failed when domain purge fails', function () {
    $tombstone = MailPendingPurge::create([
        'entity_type' => 'domain',
        'domain' => 'example.test',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('purgeDomain')
        ->once()
        ->with('example.test')
        ->andThrow(new LcCliException('Disk busy', 'lc-mail purge-domain', []));

    $job = new PurgeMailDomainJob($tombstone->id, 'example.test');

    expect(fn () => $job->handle($driver))->toThrow(LcCliException::class);

    $tombstone->refresh();
    expect($tombstone->status)->toBe('failed')
        ->and($tombstone->attempts)->toBe(1)
        ->and($tombstone->last_error)->toContain('Disk busy');
});

it('syncs mailbox quota usage when mail_core is active', function () {
    $user = User::factory()->create(['username' => 'quotauser']);
    $mailDomain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'quota.test',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    $account = MailAccount::create([
        'mail_domain_id' => $mailDomain->id,
        'local_part' => 'bob',
        'email' => 'bob@quota.test',
        'password_hash' => '{ARGON2ID}hash',
        'quota_mb' => 500,
        'used_bytes' => 0,
        'active' => true,
    ]);

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('readQuotaUsage')
        ->once()
        ->with('quota.test')
        ->andReturn(['bob@quota.test' => 12345678]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(true);

    $job = new SyncMailboxQuotasJob;
    $job->handle($driver, $registry);

    expect($account->fresh()->used_bytes)->toBe(12345678);
});

it('skips quota sync when mail_core is inactive', function () {
    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldNotReceive('readQuotaUsage');

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(false);

    $job = new SyncMailboxQuotasJob;
    $job->handle($driver, $registry);
});

it('tears down user mail domains, accounts, aliases, and queues purges when user is deleted', function () {
    Queue::fake([PurgeMailDomainJob::class, ReconcileMailServerJob::class]);

    $admin = User::factory()->create(['role' => 'admin', 'username' => 'mailadmin']);
    $tenant = User::factory()->create(['role' => 'user', 'username' => 'mailtenant']);

    $mailDomain = MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'tenant.test',
        'ownership_source' => 'webserver',
        'active' => true,
    ]);

    MailAccount::create([
        'mail_domain_id' => $mailDomain->id,
        'local_part' => 'info',
        'email' => 'info@tenant.test',
        'password_hash' => '{ARGON2ID}hash',
        'quota_mb' => 500,
        'active' => true,
    ]);

    MailAlias::create([
        'mail_domain_id' => $mailDomain->id,
        'source_email' => 'sales@tenant.test',
        'destination_email' => 'info@tenant.test',
        'active' => true,
    ]);

    $userDriver = Mockery::mock(UserDriverInterface::class);
    $userDriver->shouldReceive('deleteUser')->once();
    $userDriver->shouldReceive('removePhpCliConfig')->once();

    $mailDriver = Mockery::mock(MailServerDriverInterface::class);
    $mailDriver->shouldReceive('evictSession')->once()->with('info@tenant.test')->andReturn([]);
    $this->app->instance(MailServerDriverInterface::class, $mailDriver);

    $action = new DeleteUserAction($userDriver);
    $action->handle($tenant, $admin, '127.0.0.1');

    expect(User::find($tenant->id))->toBeNull()
        ->and(MailDomain::where('user_id', $tenant->id)->count())->toBe(0)
        ->and(MailAccount::where('mail_domain_id', $mailDomain->id)->count())->toBe(0)
        ->and(MailAlias::where('mail_domain_id', $mailDomain->id)->count())->toBe(0)
        ->and(MailPendingPurge::where('entity_type', 'domain')->where('domain', 'tenant.test')->exists())->toBeTrue();

    Queue::assertPushed(PurgeMailDomainJob::class, fn (PurgeMailDomainJob $job): bool => $job->domain === 'tenant.test');
    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job): bool => $job->scope === 'all');
});

it('includes mail data in backup when mail_core is active', function () {
    $user = User::factory()->create(['username' => 'mailbackup']);
    $backup = Backup::create([
        'user_id' => $user->id,
        'filename' => 'pending.tar.gz',
        'path' => '/home/mailbackup/backups/pending.tar.gz',
        'size_bytes' => 0,
        'status' => 'pending',
    ]);

    $backupDriver = Mockery::mock(BackupDriverInterface::class);
    $backupDriver->shouldReceive('createBackup')
        ->once()
        ->with('mailbackup', true)
        ->andReturn([
            'filename' => 'backup-mailbackup.tar.gz',
            'path' => '/home/mailbackup/backups/backup-mailbackup.tar.gz',
            'size_bytes' => 2048,
        ]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(true);

    $job = new CreateBackupJob($backup, $user);
    $job->handle($backupDriver, $registry);

    expect($backup->fresh()->status)->toBe('active');
});

it('dispatches mail reconcile after backup restore when mail_core is active', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $backupDriver = Mockery::mock(BackupDriverInterface::class);
    $backupDriver->shouldReceive('restoreBackup')->once()->andReturn(['status' => 'success']);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(true);
    $this->app->instance(ModuleRegistryQueryInterface::class, $registry);

    $job = new RestoreBackupJob(new SystemPath('/home/mailbackup/backups/backup.tar.gz'));
    $job->handle($backupDriver);

    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job): bool => $job->scope === 'all');
});

it('does not dispatch mail reconcile on backup restore when mail_core is inactive', function () {
    Queue::fake([ReconcileMailServerJob::class]);

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->once()->with('mail_core')->andReturn(false);

    $listener = new ReconcileMailOnBackupRestoredListener($registry);
    $listener->handle(new BackupRestored('/home/user/backups/backup.tar.gz'));

    Queue::assertNotPushed(ReconcileMailServerJob::class);
});
