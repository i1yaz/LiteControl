<?php

namespace Tests\Unit\Domains\WebServer;

use App\Domains\WebServer\Infrastructure\CliSslAdapter;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CliSslAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LcCliService::class, new LcCliService(mockMode: false));
    }

    public function test_dispatches_ssl_issue_with_exact_discrete_arguments(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'status' => 'success',
                'data' => ['domain' => 'example.com'],
            ])),
        ]);

        $adapter = app(CliSslAdapter::class);
        $result = $adapter->issue('example.com', '/var/www/example.com/public', 'admin@example.com');

        $this->assertSame(['domain' => 'example.com'], $result);

        Process::assertRan(function ($process) {
            return is_array($process->command)
                && array_slice($process->command, -5) === ['ssl', 'issue', 'example.com', '/var/www/example.com/public', 'admin@example.com'];
        });
    }

    public function test_dispatches_ssl_renew_and_custom_with_exact_discrete_arguments(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['status' => 'success', 'data' => []])),
        ]);

        $adapter = app(CliSslAdapter::class);
        $adapter->renew('example.com');
        $adapter->installCustom('example.com', '/tmp/cert.pem', '/tmp/key.pem');

        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -3) === ['ssl', 'renew', 'example.com']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -5) === ['ssl', 'custom', 'example.com', '/tmp/cert.pem', '/tmp/key.pem']);
    }

    public function test_throws_lc_cli_exception_when_ssl_command_fails(): void
    {
        Process::fake([
            '*' => Process::result(
                output: json_encode(['status' => 'error', 'message' => 'Certbot failed']),
                exitCode: 1
            ),
        ]);

        $adapter = app(CliSslAdapter::class);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Certbot failed');

        $adapter->issue('example.com', '/var/www/example.com', 'admin@example.com');
    }
}
