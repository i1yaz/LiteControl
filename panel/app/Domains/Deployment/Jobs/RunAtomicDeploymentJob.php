<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Jobs;

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Actions\DispatchDomainDeploymentAction;
use App\Domains\Deployment\Contracts\DeploymentDriverInterface;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\SourceControl\Actions\ResolveSourceCredentialsAction;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\ValueObjects\ResolvedFetchPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
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

    public function handle(
        BuildDeploymentPayloadAction $builder,
        ?ResolveSourceCredentialsAction $credentialResolver = null,
        ?DeploymentDriverInterface $deployDriver = null,
    ): void {
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
        $resolvedFetch = null;

        try {
            $stdinFetchPayload = null;

            if ($this->config->source_mode === SourceMode::Provider) {
                $this->config->loadMissing(['domain', 'gitProvider']);

                if ($this->config->domain === null) {
                    throw new RuntimeException('Deployment aborted: associated domain record no longer exists.');
                }

                if ($this->config->gitProvider === null) {
                    throw new RuntimeException('Deployment aborted: bound Git provider is missing.');
                }

                $resolver = $credentialResolver ?? app(ResolveSourceCredentialsAction::class);
                $resolvedFetch = $resolver->execute(
                    provider: $this->config->gitProvider,
                    domainOwnerUserId: (int) $this->config->domain->user_id,
                    remoteRepoId: (string) ($this->config->remote_repo_id ?? ''),
                    remoteRepoFullName: (string) ($this->config->remote_repo_full_name ?? ''),
                    branch: (string) ($this->config->branch ?: 'main'),
                    fetchDriver: (string) ($this->config->fetch_driver ?: 'provider_tarball'),
                    commitSha: $this->deployment->commit_sha,
                );

                if (! is_string($this->deployment->commit_sha) || trim($this->deployment->commit_sha) === '') {
                    $this->deployment->update([
                        'commit_sha' => $resolvedFetch->commitSha,
                    ]);
                }

                $stdinFetchPayload = $resolvedFetch->toStdinJson();
            }

            $payload = $builder->execute($this->config, $this->deployment);
            $specPath = (string) ($payload['spec_path'] ?? "{$payload['domain_root']}/.deploy/payloads/deploy_{$this->deployment->release_id}.json");
            $candidateKey = (string) ($payload['repo']['ssh_key_path'] ?? '');
            if ($candidateKey !== '' && str_starts_with($candidateKey, storage_path('app/deployments/'))) {
                $stagedKeyPath = $candidateKey;
            }

            $driver = $deployDriver ?? app(DeploymentDriverInterface::class);
            $streamedStderr = false;
            $runResult = $driver->runAtomicDeploy(
                specPath: $specPath,
                cancelFlagPath: $cancelFlagPath,
                onOutput: function (string $type, string $output) use ($logPath, &$streamedStderr, $resolvedFetch): void {
                    if ($type === 'err' && $output !== '') {
                        $streamedStderr = true;
                        $safeOutput = $resolvedFetch !== null ? $resolvedFetch->redactText($output) : $output;
                        $this->appendLog($logPath, $safeOutput);
                    }
                },
                stdinFetchPayload: $stdinFetchPayload,
            );

            if ($runResult['cancelled'] || File::exists($cancelFlagPath) || $this->deployment->fresh()?->status === 'failed') {
                File::delete($cancelFlagPath);

                return;
            }

            $decoded = json_decode($runResult['output'], true);
            $data = is_array($decoded) ? $decoded : [];
            $exitCode = $runResult['exit_code'];

            if ($exitCode === 0) {
                DB::transaction(function () use ($data, $resolvedFetch): void {
                    DomainDeployment::query()
                        ->where('domain_id', $this->config->domain_id)
                        ->where('status', 'active')
                        ->where('id', '!=', $this->deployment->id)
                        ->update(['status' => 'superseded']);

                    $resolvedCommitSha = (is_string($data['commit_sha'] ?? null) && trim($data['commit_sha']) !== '')
                        ? trim($data['commit_sha'])
                        : ($resolvedFetch?->commitSha ?? $this->deployment->commit_sha);

                    $this->deployment->update([
                        'status' => 'active',
                        'commit_sha' => $resolvedCommitSha,
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
                $safeStderr = $resolvedFetch !== null
                    ? $resolvedFetch->redactText(trim($runResult['error_output']))
                    : trim($runResult['error_output']);

                if (! $streamedStderr && $safeStderr !== '') {
                    $this->appendLog($logPath, $safeStderr."\n");
                }
                $this->appendLog($logPath, sprintf("[%s] %s\n", now()->format('Y-m-d H:i:s'), $summary));

                $this->deployment->update([
                    'status' => 'rolled_back',
                    'error_summary' => $summary,
                    'finished_at' => now(),
                ]);

                return;
            }

            $rawError = trim($runResult['error_output'] ?: $runResult['output']);
            if ($resolvedFetch !== null && $rawError !== '') {
                $rawError = $resolvedFetch->redactText($rawError);
            }

            $errorSummary = $this->summarizeError($rawError, $exitCode);
            if (! $streamedStderr && $rawError !== '') {
                $this->appendLog($logPath, $rawError."\n");
            }
            $this->appendLog($logPath, sprintf(
                "[%s] Deployment failed (exit code %d).\n",
                now()->format('Y-m-d H:i:s'),
                $exitCode,
            ));

            $this->deployment->update([
                'status' => 'failed',
                'error_summary' => $errorSummary,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->markFailed($e, $logPath, $resolvedFetch);
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

    private function markFailed(Throwable $e, ?string $logPath, ?ResolvedFetchPayload $resolvedFetch = null): void
    {
        $safeMessage = $resolvedFetch !== null
            ? $resolvedFetch->redactText($e->getMessage())
            : $e->getMessage();

        if ($logPath) {
            $this->appendLog($logPath, sprintf(
                "[%s] Deployment exception: %s\n",
                now()->format('Y-m-d H:i:s'),
                $safeMessage,
            ));
        }

        if (in_array($this->deployment->fresh()?->status, ['queued', 'running'], true)) {
            $this->deployment->update([
                'status' => 'failed',
                'error_summary' => Str::limit($safeMessage, 480),
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
