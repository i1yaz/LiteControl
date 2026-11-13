<?php

namespace Tests\Unit\Domains\PhpRuntime;

use App\Domains\PhpRuntime\Infrastructure\CliPhpRuntimeAdapter;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Tests\TestCase;

class CliPhpRuntimeAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LcCliService::class, new LcCliService(mockMode: false));
    }

    public function test_dispatches_php_runtime_commands_with_exact_discrete_arguments(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['status' => 'success', 'data' => ['8.3']])),
        ]);

        $adapter = app(CliPhpRuntimeAdapter::class);
        $version = new PhpVersion('8.3');
        $ext = new PhpExtensionName('redis');

        $adapter->listVersions();
        $adapter->setIni($version, 'memory_limit', '256M');
        $adapter->toggleExtension($version, $ext, 'enable');

        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -2) === ['php', 'list']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -4) === ['php', 'ini-set', '8.3', 'memory_limit=256M']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -4) === ['php', 'ext-enable', '8.3', 'redis']);
    }

    public function test_rejects_invalid_extension_action(): void
    {
        $adapter = app(CliPhpRuntimeAdapter::class);

        $this->expectException(InvalidArgumentException::class);

        $adapter->toggleExtension(new PhpVersion('8.3'), new PhpExtensionName('redis'), 'purge');
    }

    public function test_throws_lc_cli_exception_when_php_command_fails(): void
    {
        Process::fake([
            '*' => Process::result(
                output: json_encode(['status' => 'error', 'message' => 'PHP-FPM reload failed']),
                exitCode: 1
            ),
        ]);

        $adapter = app(CliPhpRuntimeAdapter::class);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('PHP-FPM reload failed');

        $adapter->installVersion(new PhpVersion('8.3'));
    }
}
