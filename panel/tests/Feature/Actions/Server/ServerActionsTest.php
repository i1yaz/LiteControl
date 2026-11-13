<?php

namespace Tests\Feature\Actions\Server;

use App\Domains\ServerOps\Actions\KillProcessAction;
use App\Domains\ServerOps\Actions\ManageServiceAction;
use App\Domains\ServerOps\Actions\ToggleWebAdminAction;
use App\Domains\ServerOps\Actions\UpdateHostnameAction;
use App\Domains\ServerOps\Actions\UpdateTimezoneAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServerActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'adminuser',
            'role' => 'admin',
        ]);
    }

    public function test_manage_service_action_success(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('executeServiceAction')
            ->once()
            ->with('openlitespeed', 'restart')
            ->andReturn([]);

        $action = new ManageServiceAction($cliMock);
        $action->handle('openlitespeed', 'restart', $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'service_restart',
            'command' => 'lc-cli server service-action openlitespeed restart',
            'status' => 'success',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_manage_service_action_failure_throws_exception(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('executeServiceAction')
            ->once()
            ->with('mariadb', 'start')
            ->andThrow(new LcCliException('Service failed to start', 'server', ['service-action', 'mariadb', 'start']));

        $action = new ManageServiceAction($cliMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Service failed to start');

        $action->handle('mariadb', 'start', $this->admin, '127.0.0.1');
    }

    public function test_toggle_webadmin_enable_action(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('enableWebAdmin')
            ->once()
            ->with('192.168.1.100')
            ->andReturn([]);

        $action = new ToggleWebAdminAction($cliMock);
        $action->enable('192.168.1.100', $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'webadmin_enabled',
            'command' => 'lc-cli server webadmin-enable 192.168.1.100',
            'status' => 'success',
        ]);
    }

    public function test_toggle_webadmin_disable_action(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('disableWebAdmin')
            ->once()
            ->andReturn([]);

        $action = new ToggleWebAdminAction($cliMock);
        $action->disable($this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'webadmin_disabled',
            'command' => 'lc-cli server webadmin-disable',
            'status' => 'success',
        ]);
    }

    public function test_kill_process_action_success(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('killProcess')
            ->once()
            ->with(1234)
            ->andReturn([]);

        $action = new KillProcessAction($cliMock);
        $action->handle(1234, $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'kill_process',
            'command' => 'lc-cli server kill-process 1234',
            'status' => 'success',
        ]);
    }

    public function test_update_timezone_action_success(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('setTimezone')
            ->once()
            ->with('America/New_York')
            ->andReturn([]);

        $action = new UpdateTimezoneAction($cliMock);
        $action->handle('America/New_York', $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_timezone',
            'command' => 'lc-cli server timezone set America/New_York',
            'status' => 'success',
        ]);
    }

    public function test_update_hostname_action_success(): void
    {
        $cliMock = Mockery::mock(ServerDriverInterface::class);
        $cliMock->shouldReceive('setHostname')
            ->once()
            ->with('cloud.server.local')
            ->andReturn([]);

        $action = new UpdateHostnameAction($cliMock);
        $action->handle('cloud.server.local', $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_hostname',
            'command' => 'lc-cli server hostname set cloud.server.local',
            'status' => 'success',
        ]);
    }
}

