<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\InstallMailCoreJob;
use App\Domains\ServerOps\ModuleRegistry;
use App\Exceptions\System\LcCliException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('registers mail stack and powerdns modules with parent and dependency links', function () {
    $defs = ModuleRegistry::definitions();

    expect($defs)->toHaveKeys(['mail_core', 'mail_filter', 'mail_webmail', 'clamav', 'dns'])
        ->and($defs['mail_core']['parent'])->toBeNull()
        ->and($defs['mail_core']['depends_on'])->toBe([])
        ->and($defs['mail_filter']['parent'])->toBe('mail_core')
        ->and($defs['mail_filter']['depends_on'])->toBe(['mail_core'])
        ->and($defs['mail_webmail']['parent'])->toBe('mail_core')
        ->and($defs['mail_webmail']['depends_on'])->toBe(['mail_core'])
        ->and($defs['clamav']['parent'])->toBe('mail_filter')
        ->and($defs['clamav']['depends_on'])->toBe(['mail_core', 'mail_filter'])
        ->and($defs['dns']['parent'])->toBeNull();
});

it('queues InstallMailCoreJob with pending state when installing mail_core via /modules', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'username' => 'mailadmin',
        'role' => 'admin',
    ]);

    $this->actingAs($admin)
        ->post('/modules/mail_core/install')
        ->assertRedirect();

    expect(Cache::get('module_install_mail_core'))->toBe('pending')
        ->and(Cache::get('module_install_current'))->toBe('mail_core');

    Queue::assertPushed(InstallMailCoreJob::class, function (InstallMailCoreJob $job): bool {
        return $job instanceof ShouldQueue && $job->afterCommit === true;
    });
});

it('transitions mail_core from pending to provisioning to active on success', function () {
    Cache::put('module_install_mail_core', 'pending', now()->addMinutes(30));

    $observedState = null;
    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('installCore')
        ->once()
        ->with('mail.example.com')
        ->andReturnUsing(function () use (&$observedState): array {
            $observedState = Cache::get('module_install_mail_core');

            return ['installed' => true];
        });

    $job = new InstallMailCoreJob('mail.example.com');
    $job->handle($driver);

    expect($observedState)->toBe('provisioning')
        ->and(Cache::get('module_install_mail_core'))->toBe('active')
        ->and(Cache::get('module_install_mail_core_error'))->toBeNull();
});

it('transitions mail_core to failed and stores error message on LcCliException', function () {
    Cache::put('module_install_mail_core', 'pending', now()->addMinutes(30));

    $driver = Mockery::mock(MailServerDriverInterface::class);
    $driver->shouldReceive('installCore')
        ->once()
        ->with('mail.example.com')
        ->andThrow(new LcCliException('Dovecot config check failed', 'lc-mail install-core'));

    $job = new InstallMailCoreJob('mail.example.com');

    try {
        $job->handle($driver);
        $this->fail('Expected LcCliException was not thrown.');
    } catch (LcCliException $e) {
        expect(Cache::get('module_install_mail_core'))->toBe('failed')
            ->and(Cache::get('module_install_mail_core_error'))->toContain('Dovecot config check failed');
    }
});
