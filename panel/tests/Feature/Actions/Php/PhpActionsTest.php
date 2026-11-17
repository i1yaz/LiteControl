<?php

namespace Tests\Feature\Actions\Php;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Actions\InstallPhpVersionAction;
use App\Domains\PhpRuntime\Actions\RemovePhpVersionAction;
use App\Domains\PhpRuntime\Actions\UpdatePhpIniAction;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;
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
        $phpMock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with(Mockery::on(fn ($v) => $v instanceof PhpVersion && $v->value === '83'), 'memory_limit', '512M')
            ->andReturn([]);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with(Mockery::on(fn ($v) => $v instanceof PhpVersion && $v->value === '83'), 'upload_max_filesize', '128M')
            ->andReturn([]);

        $action = new UpdatePhpIniAction($phpMock);
        $result = $action->handle(new PhpVersion('83'), [
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
        $phpMock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->andThrow(new LcCliException('Failed setting directive', 'php', ['ini-set', '83', 'bad=val']));

        $action = new UpdatePhpIniAction($phpMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Failed setting directive');

        $action->handle(new PhpVersion('83'), ['bad' => 'val'], $this->admin);
    }

    public function test_install_php_version_action(): void
    {
        $phpMock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $phpMock->shouldReceive('installVersion')
            ->once()
            ->with(Mockery::on(fn ($v) => $v instanceof PhpVersion && $v->value === '84'))
            ->andReturn(['version' => '84', 'installed' => true]);

        $this->app->instance(PhpRuntimeDriverInterface::class, $phpMock);
        $action = new InstallPhpVersionAction($phpMock);
        $result = $action->handle(new PhpVersion('84'), $this->admin, '10.0.0.4');

        $this->assertEquals(['status' => 'queued', 'version' => '84'], $result);

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
        $phpMock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $phpMock->shouldReceive('removeVersion')
            ->once()
            ->with(Mockery::on(fn ($v) => $v instanceof PhpVersion && $v->value === '80'))
            ->andReturn(['version' => '80', 'removed' => true]);

        $action = new RemovePhpVersionAction($phpMock);
        $result = $action->handle(new PhpVersion('80'), $this->admin, '10.0.0.5');

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
