<?php

namespace Tests\Unit\Domains\WebServer;

use App\Domains\WebServer\Infrastructure\CliVhostAdapter;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CliVhostAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LcCliService::class, new LcCliService(mockMode: false));
    }

    public function test_dispatches_vhost_create_and_remove_with_exact_discrete_arguments(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['status' => 'success', 'data' => ['ok' => true]])),
        ]);

        $adapter = app(CliVhostAdapter::class);
        $adapter->create('example.com', 'webuser', '83', '/var/www/example.com');
        $adapter->remove('example.com', true);

        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -6) === ['vhost', 'add', 'example.com', 'webuser', '83', '/var/www/example.com']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -4) === ['vhost', 'remove', 'example.com', '--delete-files']);
    }

    public function test_base64_encodes_vhost_config_on_set_conf(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['status' => 'success', 'data' => []])),
        ]);

        $adapter = app(CliVhostAdapter::class);
        $conf = "server { listen 80; }\n";
        $adapter->setConf('example.com', $conf);

        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -4) === ['vhost', 'conf-set', 'example.com', base64_encode($conf)]);
    }

    public function test_throws_lc_cli_exception_when_vhost_command_fails(): void
    {
        Process::fake([
            '*' => Process::result(
                output: json_encode(['status' => 'error', 'message' => 'Nginx syntax error']),
                exitCode: 1
            ),
        ]);

        $adapter = app(CliVhostAdapter::class);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Nginx syntax error');

        $adapter->suspend('example.com');
    }
}
