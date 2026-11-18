<?php

namespace Tests\Feature\Actions\Module;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Jobs\InstallModuleJob;
use App\Exceptions\System\LcCliException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ModuleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'moduleadmin',
            'role' => 'admin',
        ]);
    }

    public function test_install_module_action_success(): void
    {
        Queue::fake();

        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $action = new InstallModuleAction($moduleMock);
        $result = $action->handle('modsecurity', $this->admin, '10.0.0.1');

        $this->assertEquals(['status' => 'queued', 'module' => 'modsecurity'], $result);
        $this->assertEquals('pending', Cache::get('module_install_modsecurity'));
        $this->assertEquals('modsecurity', Cache::get('module_install_current'));

        Queue::assertPushed(InstallModuleJob::class, function (InstallModuleJob $job) {
            return $job->module === 'modsecurity';
        });

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'install_module',
            'command' => 'lc-cli module install modsecurity',
            'status' => 'success',
            'ip_address' => '10.0.0.1',
        ]);
    }

    public function test_install_module_job_sets_failed_status_on_failure(): void
    {
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('installModule')
            ->once()
            ->with('invalid')
            ->andThrow(new LcCliException('Installation failed', 'module', ['install', 'invalid']));

        $job = new InstallModuleJob('invalid');

        try {
            $job->handle($moduleMock);
            $this->fail('Expected LcCliException was not thrown.');
        } catch (LcCliException $e) {
            $this->assertStringContainsString('Installation failed', $e->getMessage());
            $this->assertEquals('failed', Cache::get('module_install_invalid'));
        }
    }

    public function test_module_install_status_and_dismiss_endpoints(): void
    {
        Cache::put('module_install_current', 'ftp', now()->addMinutes(30));
        Cache::put('module_install_ftp', 'provisioning', now()->addMinutes(30));

        $this->actingAs($this->admin)
            ->getJson('/modules/install-status')
            ->assertOk()
            ->assertJson([
                'module' => 'ftp',
                'status' => 'installing',
            ]);

        $this->actingAs($this->admin)
            ->postJson('/modules/install-status/dismiss')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->actingAs($this->admin)
            ->getJson('/modules/install-status')
            ->assertOk()
            ->assertJson([
                'module' => null,
                'status' => null,
            ]);
    }

    public function test_remove_module_action_success(): void
    {
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('removeModule')
            ->once()
            ->with('ftp')
            ->andReturn(['module' => 'ftp', 'installed' => false]);

        $action = new RemoveModuleAction($moduleMock);
        $result = $action->handle('ftp', $this->admin, '10.0.0.2');

        $this->assertEquals(['module' => 'ftp', 'installed' => false], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'remove_module',
            'command' => 'lc-cli module remove ftp',
            'status' => 'success',
            'ip_address' => '10.0.0.2',
        ]);
    }

    public function test_remove_module_action_throws_on_failure(): void
    {
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('removeModule')
            ->once()
            ->with('ftp')
            ->andThrow(new LcCliException('Removal failed', 'module', ['remove', 'ftp']));

        $action = new RemoveModuleAction($moduleMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Removal failed');

        $action->handle('ftp', $this->admin);
    }
}
