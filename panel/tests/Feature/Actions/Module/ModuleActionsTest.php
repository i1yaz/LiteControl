<?php

namespace Tests\Feature\Actions\Module;

use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Exceptions\System\LcCliException;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('installModule')
            ->once()
            ->with('modsecurity')
            ->andReturn(['module' => 'modsecurity', 'installed' => true]);

        $action = new InstallModuleAction($moduleMock);
        $result = $action->handle('modsecurity', $this->admin, '10.0.0.1');

        $this->assertEquals(['module' => 'modsecurity', 'installed' => true], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'install_module',
            'command' => 'lc-cli module install modsecurity',
            'status' => 'success',
            'ip_address' => '10.0.0.1',
        ]);
    }

    public function test_install_module_action_throws_on_failure(): void
    {
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('installModule')
            ->once()
            ->with('invalid')
            ->andThrow(new LcCliException('Installation failed', 'module', ['install', 'invalid']));

        $action = new InstallModuleAction($moduleMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Installation failed');

        $action->handle('invalid', $this->admin);
    }

    public function test_remove_module_action_success(): void
    {
        $moduleMock = Mockery::mock(ModuleDriverInterface::class);
        $moduleMock->shouldReceive('removeModule')
            ->once()
            ->with('csf')
            ->andReturn(['module' => 'csf', 'installed' => false]);

        $action = new RemoveModuleAction($moduleMock);
        $result = $action->handle('csf', $this->admin, '10.0.0.2');

        $this->assertEquals(['module' => 'csf', 'installed' => false], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'remove_module',
            'command' => 'lc-cli module remove csf',
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

