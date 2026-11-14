<?php

namespace Tests\Unit;

use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LcCliServiceTest extends TestCase
{
    public function test_mock_mode_returns_realistic_server_stats(): void
    {
        $service = new LcCliService('/usr/local/litecontrol/bin/lc-cli', true);

        $this->assertTrue($service->isMockMode());

        $response = $service->run('server', ['stats']);

        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('cpu_percent', $response['data']);
        $this->assertArrayHasKey('ram_percent', $response['data']);
        $this->assertArrayHasKey('disk_percent', $response['data']);
        $this->assertEquals('panel.local', $response['data']['hostname']);
    }

    public function test_mock_mode_returns_services_and_processes(): void
    {
        $service = new LcCliService('/usr/local/litecontrol/bin/lc-cli', true);

        $servicesResponse = $service->run('server', ['services']);
        $this->assertEquals('success', $servicesResponse['status']);
        $this->assertIsArray($servicesResponse['data']);
        $this->assertGreaterThanOrEqual(4, count($servicesResponse['data']));

        $processesResponse = $service->run('server', ['processes']);
        $this->assertEquals('success', $processesResponse['status']);
        $this->assertIsArray($processesResponse['data']);
    }

    public function test_real_execution_with_process_fake(): void
    {
        Process::fake([
            '*lc-cli*user*list*' => Process::result(
                output: json_encode([
                    'status' => 'success',
                    'message' => 'Users listed',
                    'data' => [
                        ['username' => 'testuser', 'uid' => 1001],
                    ],
                ]),
            ),
        ]);

        $service = new LcCliService('/usr/local/litecontrol/bin/lc-cli', false);
        $this->assertFalse($service->isMockMode());

        $response = $service->run('user', ['list']);

        $this->assertEquals('success', $response['status']);
        $this->assertEquals('Users listed', $response['message']);
        $this->assertEquals('testuser', $response['data'][0]['username']);
    }

    public function test_handles_process_failure_gracefully(): void
    {
        Process::fake([
            '*lc-cli*user*add*baduser*' => Process::result(
                output: 'User already exists',
                errorOutput: 'Invalid command arguments',
                exitCode: 1
            ),
        ]);

        $service = new LcCliService('/usr/local/litecontrol/bin/lc-cli', false);
        $response = $service->run('user', ['add', 'baduser', 'invalid']);

        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid command arguments', $response['message']);
    }
}
