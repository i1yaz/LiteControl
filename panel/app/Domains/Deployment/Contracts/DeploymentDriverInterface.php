<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Contracts;

use App\Exceptions\System\LcCliException;
use Closure;

interface DeploymentDriverInterface
{
    /**
     * Execute `lc-cli deploy run` for the given spec path, optionally piping a versioned
     * provider fetch payload over stdin.
     *
     * @param  Closure(string, string): void  $onOutput
     * @return array{cancelled: bool, exit_code: int, output: string, error_output: string}
     *
     * @throws LcCliException
     */
    public function runAtomicDeploy(
        string $specPath,
        string $cancelFlagPath,
        Closure $onOutput,
        ?string $stdinFetchPayload = null,
    ): array;
}
