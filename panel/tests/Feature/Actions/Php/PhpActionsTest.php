<?php

namespace Tests\Feature\Actions\Php;

use App\Actions\Php\InstallPhpVersionAction;
use App\Actions\Php\RemovePhpVersionAction;
use App\Actions\Php\TogglePhpExtensionAction;
use App\Actions\Php\UpdatePhpIniAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PhpActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'phpadmin',
            'role' => 'admin',
        ]);
    }

    public function test_update_php_ini_action_success(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with('83', 'memory_limit', '512M')
            ->andReturn([]);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with('83', 'upload_max_filesize', '128M')
            ->andReturn([]);

        $action = new UpdatePhpIniAction($phpMock);
        $result = $action->handle('83', [
            'memory_limit' => '512M',
            'upload_max_filesize' => '128M',
            'null_directive' => null,
        ], $this->admin, '10.0.0.1');

        $this->assertEquals(['memory_limit', 'upload_max_filesize'], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'php.ini_update',
            'command' => 'lc-cli php ini-set 83',
            'status' => 'success',
            'ip_address' => '10.0.0.1',
        ]);
    }

    public function test_update_php_ini_action_throws_on_failure(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->andThrow(new LcCliException('Failed setting directive', 'php', ['ini-set', '83', 'bad=val']));

        $action = new UpdatePhpIniAction($phpMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Failed setting directive');

        $action->handle('83', ['bad' => 'val'], $this->admin);
    }

    public function test_toggle_php_extension_action_enable(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('toggleExtension')
            ->once()
            ->with('83', 'redis', 'enable')
            ->andReturn(['enabled' => true]);

        $action = new TogglePhpExtensionAction($phpMock);
        $result = $action->handle('83', 'redis', 'enable', $this->admin, '10.0.0.2');

        $this->assertEquals(['enabled' => true], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'php.extension_enable',
            'command' => 'lc-cli php ext-enable 83 redis',
            'status' => 'success',
            'ip_address' => '10.0.0.2',
        ]);
    }

    public function test_toggle_php_extension_action_disable(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('toggleExtension')
            ->once()
            ->with('82', 'xdebug', 'disable')
            ->andReturn(['enabled' => false]);

        $action = new TogglePhpExtensionAction($phpMock);
        $result = $action->handle('82', 'xdebug', 'disable', $this->admin, '10.0.0.3');

        $this->assertEquals(['enabled' => false], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'php.extension_disable',
            'command' => 'lc-cli php ext-disable 82 xdebug',
            'status' => 'success',
            'ip_address' => '10.0.0.3',
        ]);
    }

    public function test_install_php_version_action(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('installVersion')
            ->once()
            ->with('84')
            ->andReturn(['version' => '84', 'installed' => true]);

        $action = new InstallPhpVersionAction($phpMock);
        $result = $action->handle('84', $this->admin, '10.0.0.4');

        $this->assertEquals(['version' => '84', 'installed' => true], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'php.install',
            'command' => 'lc-cli php install 84',
            'status' => 'success',
            'ip_address' => '10.0.0.4',
        ]);
    }

    public function test_remove_php_version_action(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('removeVersion')
            ->once()
            ->with('80')
            ->andReturn(['version' => '80', 'removed' => true]);

        $action = new RemovePhpVersionAction($phpMock);
        $result = $action->handle('80', $this->admin, '10.0.0.5');

        $this->assertEquals(['version' => '80', 'removed' => true], $result);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'php.remove',
            'command' => 'lc-cli php remove 80',
            'status' => 'success',
            'ip_address' => '10.0.0.5',
        ]);
    }
}

