<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\InstallMailCoreJob;
use App\Domains\Mail\Jobs\InstallMailFilterJob;
use App\Domains\Mail\Jobs\InstallWebmailJob;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'username' => 'moduiadmin',
        'role' => 'admin',
    ]);
    $this->client = User::factory()->create([
        'username' => 'moduiclient',
        'role' => 'user',
    ]);
});

it('forbids non-admin users from viewing modules page', function () {
    $this->actingAs($this->client)
        ->get('/modules')
        ->assertForbidden();
});

it('renders mail stack module props with status, dependencies, ram, and webmail launch url', function () {
    Cache::put('module_install_mail_filter', 'provisioning', now()->addMinutes(5));
    Cache::put('module_install_clamav', 'failed', now()->addMinutes(5));
    Cache::put('module_install_clamav_error', 'ClamAV daemon failed to start', now()->addMinutes(5));
    Cache::forever('mail_webmail_hostname', 'webmail.example.com');

    $moduleMock = Mockery::mock(ModuleDriverInterface::class);
    $moduleMock->shouldReceive('listModules')->once()->andReturn([
        ['name' => 'mail_core', 'installed' => true],
        ['name' => 'mail_webmail', 'installed' => true],
    ]);
    $moduleMock->shouldReceive('getModuleStatus')
        ->with('mail_core')
        ->andReturn(['installed' => true, 'status' => 'active']);
    $this->app->instance(ModuleDriverInterface::class, $moduleMock);

    $serverMock = Mockery::mock(ServerDriverInterface::class);
    $serverMock->shouldReceive('getStats')->once()->andReturn([
        'ram_total_mb' => 8192,
    ]);
    $this->app->instance(ServerDriverInterface::class, $serverMock);

    $this->actingAs($this->admin)
        ->get('/modules')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServerOps/Modules')
            ->where('server_ram_mb', 8192)
            ->has('modules', fn (Assert $modules) => $modules
                ->each(fn (Assert $m) => $m
                    ->hasAll(['key', 'status', 'driver', 'parent_key', 'is_active', 'error_message'])
                    ->etc()
                )
            )
            ->where('modules', function ($modules) {
                $byKey = collect($modules)->keyBy('key');

                return $byKey['mail_core']['status'] === 'active'
                    && $byKey['mail_core']['is_active'] === true
                    && $byKey['mail_filter']['status'] === 'provisioning'
                    && $byKey['mail_filter']['parent_key'] === 'mail_core'
                    && $byKey['clamav']['status'] === 'failed'
                    && $byKey['clamav']['parent_key'] === 'mail_filter'
                    && $byKey['clamav']['error_message'] === 'ClamAV daemon failed to start'
                    && $byKey['mail_webmail']['is_active'] === true
                    && $byKey['mail_webmail']['launch_url'] === 'https://webmail.example.com/';
            })
        );
});

it('requires a valid fqdn hostname when installing mail_core and mail_webmail', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/modules/mail_core/install', ['hostname' => 'not-a-fqdn'])
        ->assertSessionHasErrors('hostname');

    $this->actingAs($this->admin)
        ->post('/modules/mail_core/install', ['hostname' => 'mail.example.com'])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(InstallMailCoreJob::class, fn (InstallMailCoreJob $job) => $job->hostname === 'mail.example.com');

    $this->actingAs($this->admin)
        ->post('/modules/mail_webmail/install', ['hostname' => 'webmail.example.com'])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(InstallWebmailJob::class, fn (InstallWebmailJob $job) => $job->hostname === 'webmail.example.com');
});

it('enforces the 4096 MB ram gate when installing clamav', function () {
    Queue::fake();

    $serverMock = Mockery::mock(ServerDriverInterface::class);
    $serverMock->shouldReceive('getStats')
        ->twice()
        ->andReturn(['ram_total_mb' => 2048], ['ram_total_mb' => 4096]);
    $this->app->instance(ServerDriverInterface::class, $serverMock);

    $this->actingAs($this->admin)
        ->post('/modules/clamav/install')
        ->assertRedirect()
        ->assertSessionHas('error');

    Queue::assertNotPushed(InstallMailFilterJob::class);

    $this->actingAs($this->admin)
        ->post('/modules/clamav/install')
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(InstallMailFilterJob::class, fn (InstallMailFilterJob $job) => $job->moduleKey === 'clamav' && $job->withAntivirus === true);
});

it('passes purge_data flag when uninstalling mail_core and demotes rspamd on mail_filter uninstall', function () {
    $mailServerMock = Mockery::mock(MailServerDriverInterface::class);
    $mailServerMock->shouldReceive('uninstallCore')->once()->with(false)->andReturn(['status' => 'ok']);
    $mailServerMock->shouldReceive('uninstallCore')->once()->with(true)->andReturn(['status' => 'ok']);
    $this->app->instance(MailServerDriverInterface::class, $mailServerMock);

    $this->actingAs($this->admin)
        ->post('/modules/mail_core/remove')
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->actingAs($this->admin)
        ->post('/modules/mail_core/remove', ['purge_data' => true])
        ->assertRedirect()
        ->assertSessionHas('success');

    $filterMock = Mockery::mock(MailFilterDriverInterface::class);
    $filterMock->shouldReceive('demoteToLight')->once()->andReturn(['status' => 'ok']);
    $this->app->instance(MailFilterDriverInterface::class, $filterMock);

    $this->actingAs($this->admin)
        ->post('/modules/mail_filter/remove')
        ->assertRedirect()
        ->assertSessionHas('success');
});
