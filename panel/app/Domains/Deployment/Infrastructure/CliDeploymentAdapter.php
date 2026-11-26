<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Infrastructure;

use App\Domains\Deployment\Contracts\DeploymentDriverInterface;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class CliDeploymentAdapter implements DeploymentDriverInterface
{
    public function runAtomicDeploy(
        string $specPath,
        string $cancelFlagPath,
        Closure $onOutput,
        ?string $stdinFetchPayload = null,
    ): array {
        $cliPath = (string) env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');

        $command = [
            'sudo',
            $cliPath,
            'deploy',
            'run',
            "--spec={$specPath}",
        ];

        $pending = Process::timeout(580);

        if ($stdinFetchPayload !== null) {
            $command[] = '--fetch-payload-stdin';
            $pending = $pending->input($stdinFetchPayload);
        }

        $running = $pending->start($command, $onOutput);

        while ($running->running()) {
            if (File::exists($cancelFlagPath)) {
                try {
                    $running->stop(2);
                } catch (Throwable) {
                    // Process may be elevated under sudo; still stop waiting and respect cancellation.
                }
                File::delete($cancelFlagPath);

                return [
                    'cancelled' => true,
                    'exit_code' => -1,
                    'output' => '',
                    'error_output' => '',
                ];
            }
            usleep(250_000);
        }

        $result = $running->wait();

        return [
            'cancelled' => false,
            'exit_code' => (int) ($result->exitCode() ?? 1),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ];
    }
}
