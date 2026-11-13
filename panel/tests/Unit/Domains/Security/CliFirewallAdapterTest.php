<?php

namespace Tests\Unit\Domains\Security;

use App\Domains\Security\Infrastructure\CliFirewallAdapter;
use App\Domains\Security\ValueObjects\Port;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CliFirewallAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LcCliService::class, new LcCliService(mockMode: false));
    }

    public function test_dispatches_security_firewall_commands_with_exact_discrete_arguments(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['status' => 'success', 'data' => []])),
        ]);

        $adapter = app(CliFirewallAdapter::class);
        $adapter->addRule('allow', '443', 'tcp', '10.0.0.1');
        $adapter->deleteRule('1');
        $adapter->updatePanelPort(new Port(8443));
        $adapter->updateSshPort(new Port(2222));

        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -5) === ['security', 'ufw-allow', '443', 'tcp', '10.0.0.1']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -3) === ['security', 'ufw-delete', '1']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -3) === ['security', 'panel-port', '8443']);
        Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, -3) === ['security', 'ssh-port', '2222']);
    }

    public function test_throws_lc_cli_exception_when_security_command_fails_or_returns_non_success_status(): void
    {
        Process::fake([
            '*' => Process::result(
                output: json_encode(['status' => 'error', 'message' => 'UFW rule rejected']),
                exitCode: 1
            ),
        ]);

        $adapter = app(CliFirewallAdapter::class);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('UFW rule rejected');

        $adapter->addRule('allow', '80');
    }
}
