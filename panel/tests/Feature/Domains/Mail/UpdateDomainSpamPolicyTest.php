<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Actions\UpdateDomainSpamPolicyAction;
use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use App\Domains\Mail\Infrastructure\CliRspamdFilterAdapter;
use App\Domains\Mail\Jobs\InstallMailFilterJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\SpamPolicy;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('updates domain spam policy and dispatches filter reconcile when mail_filter is active', function (): void {
    Queue::fake();

    $user = User::factory()->create(['username' => 'mailowner']);
    $domain = MailDomain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 6.0,
        'spam_reject_score' => 15.0,
    ]);

    $audit = Mockery::mock(AuditLoggerInterface::class);
    $audit->shouldReceive('log')->once();

    $registry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $registry->shouldReceive('isActive')->with('mail_filter')->once()->andReturn(true);

    $action = new UpdateDomainSpamPolicyAction($audit, $registry);
    $updated = $action->execute($user, $domain, new SpamPolicy(true, 5.5, 12.0));

    expect($updated->spam_filter_enabled)->toBeTrue()
        ->and($updated->spam_header_score)->toBe(5.5)
        ->and($updated->spam_reject_score)->toBe(12.0);

    Queue::assertPushed(ReconcileMailServerJob::class, fn (ReconcileMailServerJob $job) => $job->scope === 'filter');
});

it('calls lc-mail filter-enable and filter-demote via CliRspamdFilterAdapter', function (): void {
    $cli = Mockery::mock(LcCliService::class);
    $cli->shouldReceive('runMail')
        ->with('filter-enable', ['--driver=rspamd', '--with-clamav'])
        ->once()
        ->andReturn(['status' => 'success', 'data' => ['mode' => 'full']]);

    $cli->shouldReceive('runMail')
        ->with('filter-demote', ['--driver=rspamd'])
        ->once()
        ->andReturn(['status' => 'success', 'data' => ['mode' => 'light', 'dkim_preserved' => true]]);

    $adapter = new CliRspamdFilterAdapter($cli);

    expect($adapter->key())->toBe('rspamd')
        ->and($adapter->minimumRamMb(false))->toBe(1024)
        ->and($adapter->minimumRamMb(true))->toBe(4096)
        ->and($adapter->install(true))->toBe(['mode' => 'full'])
        ->and($adapter->demoteToLight())->toBe(['mode' => 'light', 'dkim_preserved' => true]);
});

it('transitions InstallMailFilterJob cache state on success and failure', function (): void {
    $driver = Mockery::mock(MailFilterDriverInterface::class);
    $driver->shouldReceive('install')->with(true)->once()->andReturn(['status' => 'success']);

    $job = new InstallMailFilterJob('clamav', true);
    $job->handle($driver);

    expect(Cache::get('module_install_clamav'))->toBe('active');

    $failingDriver = Mockery::mock(MailFilterDriverInterface::class);
    $failingDriver->shouldReceive('install')->with(true)->once()->andThrow(new LcCliException('insufficient RAM'));

    expect(fn () => (new InstallMailFilterJob('clamav', true))->handle($failingDriver))
        ->toThrow(LcCliException::class);

    expect(Cache::get('module_install_clamav'))->toBe('failed')
        ->and(Cache::get('module_install_clamav_error'))->toBe('insufficient RAM');
});

it('demotes mail_filter to light mode on module removal instead of removing rspamd', function (): void {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $filterDriver = Mockery::mock(MailFilterDriverInterface::class);
    $filterDriver->shouldReceive('demoteToLight')->once()->andReturn(['mode' => 'light', 'dkim_preserved' => true]);
    app()->instance(MailFilterDriverInterface::class, $filterDriver);

    $moduleDriver = Mockery::mock(ModuleDriverInterface::class);
    $moduleDriver->shouldNotReceive('removeModule');

    $audit = Mockery::mock(AuditLoggerInterface::class);
    $audit->shouldReceive('log')->once();

    $action = new RemoveModuleAction($moduleDriver, $audit);
    $res = $action->handle('mail_filter', $user, '127.0.0.1');

    expect($res)->toBe(['mode' => 'light', 'dkim_preserved' => true]);
});

it('updates spam policy via HTTP PUT endpoint with authorization and validation', function (): void {
    Queue::fake();

    $owner = User::factory()->create(['username' => 'tenantowner', 'role' => 'user']);
    $other = User::factory()->create(['username' => 'otheruser', 'role' => 'user']);

    $domain = MailDomain::create([
        'user_id' => $owner->id,
        'domain' => 'tenant.test',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 6.0,
        'spam_reject_score' => 15.0,
    ]);

    $this->actingAs($other)
        ->put(route('email.domains.spam-policy.update', $domain), [
            'spam_filter_enabled' => false,
            'spam_header_score' => 5.0,
            'spam_reject_score' => 12.0,
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->put(route('email.domains.spam-policy.update', $domain), [
            'spam_filter_enabled' => true,
            'spam_header_score' => 15.0,
            'spam_reject_score' => 10.0,
        ])
        ->assertSessionHasErrors('spam_reject_score');

    $this->actingAs($owner)
        ->put(route('email.domains.spam-policy.update', $domain), [
            'spam_filter_enabled' => false,
            'spam_header_score' => 4.5,
            'spam_reject_score' => 11.5,
        ])
        ->assertRedirect();

    $domain->refresh();
    expect($domain->spam_filter_enabled)->toBeFalse()
        ->and($domain->spam_header_score)->toBe(4.5)
        ->and($domain->spam_reject_score)->toBe(11.5);
});
