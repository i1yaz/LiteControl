<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Jobs;

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Actions\DispatchDomainDeploymentAction;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

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
        $cancelFlagPath = storage_path("app/deployments/domain_{$this->config->domain_id}/cancel_{$this->deployment->release_id}");
        if (File::exists($cancelFlagPath) || $this->deployment->fresh()?->status === 'failed') {
            File::delete($cancelFlagPath);

            return;
        }

        $logDir = storage_path('logs/deployments');
        File::ensureDirectoryExists($logDir, 0750);
        $logPath = "{$logDir}/domain_{$this->config->domain_id}_{$this->deployment->release_id}.log";

        $this->appendLog($logPath, sprintf(
            "[%s] Starting atomic deployment %s...\n",
            now()->format('Y-m-d H:i:s'),
            $this->deployment->release_id,
        ));

        $this->deployment->update([
            'status' => 'running',
            'log_path' => $logPath,
            'started_at' => now(),
        ]);

        $stagedKeyPath = null;

        try {
            $payload = $builder->execute($this->config, $this->deployment);
            $specPath = (string) ($payload['spec_path'] ?? "{$payload['domain_root']}/.deploy/payloads/deploy_{$this->deployment->release_id}.json");
            $candidateKey = (string) ($payload['repo']['ssh_key_path'] ?? '');
            if ($candidateKey !== '' && str_starts_with($candidateKey, storage_path('app/deployments/'))) {
                $stagedKeyPath = $candidateKey;
            }
            $cliPath = (string) env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');

            $streamedStderr = false;
            $running = Process::timeout(580)->start([
                'sudo',
                $cliPath,
                'deploy',
                'run',
                "--spec={$specPath}",
            ], function (string $type, string $output) use ($logPath, &$streamedStderr): void {
                if ($type === 'err' && $output !== '') {
                    $streamedStderr = true;
                    $this->appendLog($logPath, $output);
                }
            });

            while ($running->running()) {
                if (File::exists($cancelFlagPath)) {
                    try {
                        $running->stop(2);
                    } catch (Throwable) {
                        // Process may be elevated under sudo; still stop waiting and respect cancellation.
                    }
                    File::delete($cancelFlagPath);

                    return;
                }
                usleep(250_000);
            }

            $result = $running->wait();

            if (File::exists($cancelFlagPath) || $this->deployment->fresh()?->status === 'failed') {
                File::delete($cancelFlagPath);

                return;
            }

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
                        'commit_message' => isset($data['commit_message'])
                            ? Str::limit((string) $data['commit_message'], 490)
                            : $this->deployment->commit_message,
                        'commit_author' => isset($data['commit_author'])
                            ? Str::limit((string) $data['commit_author'], 140)
                            : $this->deployment->commit_author,
                        'finished_at' => now(),
                    ]);
                });

                $this->appendLog($logPath, sprintf(
                    "[%s] Deployment %s completed successfully.\n",
                    now()->format('Y-m-d H:i:s'),
                    $this->deployment->release_id,
                ));

                return;
            }

            if ($exitCode === 2) {
                $summary = 'HTTP health check failed (5xx); auto-rolled back to previous release.';
                if (! $streamedStderr && trim($result->errorOutput()) !== '') {
                    $this->appendLog($logPath, trim($result->errorOutput())."\n");
                }
                $this->appendLog($logPath, sprintf("[%s] %s\n", now()->format('Y-m-d H:i:s'), $summary));

                $this->deployment->update([
                    'status' => 'rolled_back',
                    'error_summary' => $summary,
                    'finished_at' => now(),
                ]);

                return;
            }

            $rawError = trim($result->errorOutput() ?: $result->output());
            $errorSummary = $this->summarizeError($rawError, (int) $exitCode);
            if (! $streamedStderr && $rawError !== '') {
                $this->appendLog($logPath, $rawError."\n");
            }
            $this->appendLog($logPath, sprintf(
                "[%s] Deployment failed (exit code %d).\n",
                now()->format('Y-m-d H:i:s'),
                (int) $exitCode,
            ));

            $this->deployment->update([
                'status' => 'failed',
                'error_summary' => $errorSummary,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->markFailed($e, $logPath);
        } finally {
            if ($stagedKeyPath !== null && File::exists($stagedKeyPath)) {
                File::delete($stagedKeyPath);
            }

            try {
                $this->pruneOldDeploymentArtifacts();
            } catch (Throwable) {
                // Best-effort cleanup; never mask deployment outcome.
            }

            try {
                app(DispatchDomainDeploymentAction::class)->releaseAndDispatchPending($this->config);
            } catch (Throwable) {
                // Best-effort lock release and coalesced follow-up dispatch.
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception, $this->deployment->log_path);

        try {
            app(DispatchDomainDeploymentAction::class)->releaseAndDispatchPending($this->config);
        } catch (Throwable) {
            // Best-effort lock release.
        }
    }

    private function pruneOldDeploymentArtifacts(): void
    {
        $keepReleases = max(1, (int) ($this->config->keep_releases ?? 5));

        $retainedIds = DomainDeployment::query()
            ->where('domain_id', $this->config->domain_id)
            ->orderByDesc('id')
            ->limit($keepReleases)
            ->pluck('id')
            ->all();

        $oldDeployments = DomainDeployment::query()
            ->where('domain_id', $this->config->domain_id)
            ->whereNotIn('id', $retainedIds)
            ->whereNotIn('status', ['active', 'running', 'queued'])
            ->get();

        $payloadsDir = storage_path("app/deployments/domain_{$this->config->domain_id}/payloads");

        foreach ($oldDeployments as $old) {
            if ($old->log_path && File::exists($old->log_path)) {
                File::delete($old->log_path);
            }

            $oldPayload = "{$payloadsDir}/deploy_{$old->release_id}.json";
            if (File::exists($oldPayload)) {
                File::delete($oldPayload);
            }

            $old->delete();
        }
    }

    private function markFailed(Throwable $e, ?string $logPath): void
    {
        if ($logPath) {
            $this->appendLog($logPath, sprintf(
                "[%s] Deployment exception: %s\n",
                now()->format('Y-m-d H:i:s'),
                $e->getMessage(),
            ));
        }

        if (in_array($this->deployment->fresh()?->status, ['queued', 'running'], true)) {
            $this->deployment->update([
                'status' => 'failed',
                'error_summary' => Str::limit($e->getMessage(), 480),
                'finished_at' => now(),
            ]);
        }
    }

    private function summarizeError(string $rawError, int $exitCode): string
    {
        if ($rawError === '') {
            return "Deployment failed with exit code {$exitCode}.";
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $rawError) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (str_starts_with($lines[$i], 'error:')) {
                return Str::limit($lines[$i], 480);
            }
        }

        $lastLine = $lines !== [] ? $lines[count($lines) - 1] : $rawError;

        return Str::limit($lastLine, 480);
    }

    private function appendLog(string $logPath, string $content): void
    {
        File::append($logPath, $content);
    }
}
