<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Jobs;

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class RunAtomicDeploymentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly DomainDeploymentConfig $config,
        public readonly DomainDeployment $deployment,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->config->domain_id;
    }

    public function handle(BuildDeploymentPayloadAction $builder): void
    {
        $this->deployment->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $payload = $builder->execute($this->config, $this->deployment);
        $specPath = "{$payload['domain_root']}/.deploy/payloads/deploy_{$this->deployment->release_id}.json";

        $result = Process::timeout(580)->run([
            'sudo',
            '/usr/local/bin/lc-cli',
            'deploy',
            'run',
            "--spec={$specPath}",
        ]);

        $decoded = json_decode($result->output(), true);
        $data = is_array($decoded) ? $decoded : [];
        $exitCode = $result->exitCode();

        if ($exitCode === 0) {
            DB::transaction(function () use ($data): void {
                DomainDeployment::query()
                    ->where('domain_id', $this->config->domain_id)
                    ->where('status', 'active')
                    ->where('id', '!=', $this->deployment->id)
                    ->update(['status' => 'superseded']);

                $this->deployment->update([
                    'status' => 'active',
                    'commit_sha' => $data['commit_sha'] ?? $this->deployment->commit_sha,
                    'commit_message' => $data['commit_message'] ?? $this->deployment->commit_message,
                    'commit_author' => $data['commit_author'] ?? $this->deployment->commit_author,
                    'finished_at' => now(),
                ]);
            });

            return;
        }

        if ($exitCode === 2) {
            $this->deployment->update([
                'status' => 'rolled_back',
                'error_summary' => 'HTTP health check failed (5xx); auto-rolled back to previous release.',
                'finished_at' => now(),
            ]);

            return;
        }

        $this->deployment->update([
            'status' => 'failed',
            'error_summary' => trim($result->errorOutput() ?: $result->output()) ?: "Deployment failed with exit code {$exitCode}.",
            'finished_at' => now(),
        ]);
    }
}
