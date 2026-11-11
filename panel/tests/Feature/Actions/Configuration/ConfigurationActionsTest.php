<?php

namespace Tests\Feature\Actions\Configuration;

use App\Actions\Configuration\UpdatePhpLimitsAction;
use App\Actions\Configuration\UpdateSettingsAction;
use App\Actions\Configuration\UpdateTuningAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;
use App\Services\System\ServerCliService;
use App\Services\System\TuningCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConfigurationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'admincfg',
            'role' => 'admin',
        ]);
    }

    public function test_update_settings_action_success(): void
    {
        $serverMock = Mockery::mock(ServerCliService::class);
        $serverMock->shouldReceive('setTimezone')
            ->once()
            ->with('America/New_York')
            ->andReturn([]);

        $action = new UpdateSettingsAction($serverMock);
        $settings = [
            'panel_name' => 'LiteControl Master',
            'admin_email' => 'admin@domain.com',
            'timezone' => 'America/New_York',
            'default_php' => '83',
            'default_theme' => 'nordic',
        ];

        $action->handle($settings, $this->admin, '192.168.1.100');

        $this->admin->refresh();
        $this->assertEquals('nordic', $this->admin->theme);
        $this->assertEquals('nordic', session('theme'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_settings',
            'command' => 'configuration.settings',
            'status' => 'success',
            'ip_address' => '192.168.1.100',
        ]);
    }

    public function test_update_settings_action_throws_on_failure(): void
    {
        $serverMock = Mockery::mock(ServerCliService::class);
        $serverMock->shouldReceive('setTimezone')
            ->once()
            ->with('Invalid/Zone')
            ->andThrow(new LcCliException('Invalid timezone', 'server', ['timezone', 'set', 'Invalid/Zone']));

        $action = new UpdateSettingsAction($serverMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Invalid timezone');

        $action->handle([
            'panel_name' => 'LiteControl',
            'admin_email' => 'admin@domain.com',
            'timezone' => 'Invalid/Zone',
            'default_php' => '83',
        ], $this->admin, '127.0.0.1');
    }

    public function test_update_tuning_action_success(): void
    {
        $tuningMock = Mockery::mock(TuningCliService::class);
        $params = [
            'maxConnections' => 20000,
            'maxSSLConnections' => 15000,
            'connTimeout' => 300,
            'maxKeepAliveReq' => 1000,
            'keepAliveTimeout' => 5,
            'maxReqBodySize' => '200M',
            'enableGzipCompress' => 1,
            'gzipCompressLevel' => 6,
            'quicEnable' => 1,
        ];

        $tuningMock->shouldReceive('updateTuning')
            ->once()
            ->with($params)
            ->andReturn([]);

        $action = new UpdateTuningAction($tuningMock);
        $action->handle($params, $this->admin, '10.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_tuning',
            'command' => 'lc-cli tuning set',
            'status' => 'success',
            'ip_address' => '10.0.0.1',
        ]);
    }

    public function test_update_tuning_action_throws_on_failure(): void
    {
        $tuningMock = Mockery::mock(TuningCliService::class);
        $tuningMock->shouldReceive('updateTuning')
            ->once()
            ->andThrow(new LcCliException('Tuning error', 'tuning', ['set']));

        $action = new UpdateTuningAction($tuningMock);

        $this->expectException(LcCliException::class);

        $action->handle(['maxConnections' => 5], $this->admin);
    }

    public function test_update_php_limits_action_success(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with('83', 'memory_limit', '512M')
            ->andReturn([]);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with('83', 'max_execution_time', '600')
            ->andReturn([]);

        $action = new UpdatePhpLimitsAction($phpMock);
        $directives = [
            'memory_limit' => '512M',
            'max_execution_time' => 600,
        ];

        $action->handle('83', $directives, $this->admin, '172.16.0.5');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_php_limits',
            'command' => 'lc-cli php ini-set 83',
            'status' => 'success',
            'ip_address' => '172.16.0.5',
        ]);
    }

    public function test_update_php_limits_action_throws_on_failure(): void
    {
        $phpMock = Mockery::mock(PhpCliService::class);
        $phpMock->shouldReceive('setIni')
            ->once()
            ->with('83', 'memory_limit', '99999M')
            ->andThrow(new LcCliException('Failed to set INI directive', 'php', ['ini-set']));

        $action = new UpdatePhpLimitsAction($phpMock);

        $this->expectException(LcCliException::class);

        $action->handle('83', ['memory_limit' => '99999M'], $this->admin);
    }
}

