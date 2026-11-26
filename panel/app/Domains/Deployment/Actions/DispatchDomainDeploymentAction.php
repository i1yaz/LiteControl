<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DispatchDomainDeploymentAction
{
    public const DEPLOY_LOCK_PREFIX = 'deploy_lock:';

    public const PENDING_DEPLOY_PREFIX = 'pending_deploy:';

    public const DEPLOY_LOCK_TTL_SECONDS = 660;

    public const PENDING_DEPLOY_TTL_SECONDS = 1800;

    public static function lockKey(int $domainId): string
    {
        return self::DEPLOY_LOCK_PREFIX.$domainId;
    }

    public static function pendingKey(int $domainId): string
    {
        return self::PENDING_DEPLOY_PREFIX.$domainId;
    }

    /**
     * Dispatch an atomic deployment with per-domain lock & single-slot pending SHA coalescing.
     *
     * @return array{status: 'dispatched'|'coalesced', deployment: ?DomainDeployment}
     */
    public function handle(
        DomainDeploymentConfig $config,
        ?string $commitSha = null,
        string $trigger = 'webhook',
        ?string $commitMessage = null,
        ?string $commitAuthor = null,
    ): array {
        $lockKey = self::lockKey($config->domain_id);
        $lock = Cache::lock($lockKey, self::DEPLOY_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->storePendingDeploy(
                domainId: $config->domain_id,
                commitSha: $commitSha,
                trigger: $trigger,
                commitMessage: $commitMessage,
                commitAuthor: $commitAuthor,
            );

            Log::info('Coalesced concurrent deployment push into pending slot.', [
                'domain_id' => $config->domain_id,
                'commit_sha' => $commitSha,
                'trigger' => $trigger,
            ]);

            return [
                'status' => 'coalesced',
                'deployment' => null,
            ];
        }

        $deployment = null;

        try {
            $deployment = DomainDeployment::create([
                'domain_id' => $config->domain_id,
                'release_id' => now()->format('Ymd_His').'_'.Str::lower(Str::random(4)),
                'commit_sha' => $commitSha,
                'commit_message' => $commitMessage !== null ? Str::limit($commitMessage, 490) : null,
                'commit_author' => $commitAuthor !== null ? Str::limit($commitAuthor, 140) : null,
                'trigger_type' => $trigger,
                'status' => 'queued',
            ]);

            RunAtomicDeploymentJob::dispatch($config, $deployment);

            return [
                'status' => 'dispatched',
                'deployment' => $deployment,
            ];
        } catch (Throwable $e) {
            if ($deployment !== null && $deployment->exists) {
                $deployment->update([
                    'status' => 'failed',
                    'error_summary' => Str::limit('Failed to enqueue deployment job: '.$e->getMessage(), 480),
                    'finished_at' => now(),
                ]);
            }

            $lock->release();

            throw $e;
        }
    }

    /**
     * Release the per-domain deploy lock and trigger at most one follow-up deploy if a pending SHA was coalesced.
     *
     * @return array{status: 'dispatched'|'coalesced', deployment: ?DomainDeployment}|null
     */
    public function releaseAndDispatchPending(DomainDeploymentConfig $config): ?array
    {
        Cache::lock(self::lockKey($config->domain_id))->forceRelease();
        Cache::lock('laravel_unique_job:'.RunAtomicDeploymentJob::class.':'.$config->domain_id)->forceRelease();

        $pending = Cache::pull(self::pendingKey($config->domain_id));
        if (! is_array($pending)) {
            return null;
        }

        $freshConfig = $config->fresh();
        if (! $freshConfig) {
            return null;
        }

        $commitSha = is_string($pending['commit_sha'] ?? null) ? $pending['commit_sha'] : null;
        $trigger = is_string($pending['trigger'] ?? null) && $pending['trigger'] !== ''
            ? $pending['trigger']
            : 'webhook';
        $commitMessage = is_string($pending['commit_message'] ?? null) ? $pending['commit_message'] : null;
        $commitAuthor = is_string($pending['commit_author'] ?? null) ? $pending['commit_author'] : null;

        Log::info('Dispatching coalesced follow-up deployment after previous run completed.', [
            'domain_id' => $freshConfig->domain_id,
            'commit_sha' => $commitSha,
            'trigger' => $trigger,
        ]);

        return $this->handle(
            config: $freshConfig,
            commitSha: $commitSha,
            trigger: $trigger,
            commitMessage: $commitMessage,
            commitAuthor: $commitAuthor,
        );
    }

    private function storePendingDeploy(
        int $domainId,
        ?string $commitSha,
        string $trigger,
        ?string $commitMessage,
        ?string $commitAuthor,
    ): void {
        Cache::put(
            self::pendingKey($domainId),
            [
                'commit_sha' => $commitSha,
                'trigger' => $trigger,
                'commit_message' => $commitMessage,
                'commit_author' => $commitAuthor,
                'queued_at' => now()->toIso8601String(),
            ],
            self::PENDING_DEPLOY_TTL_SECONDS,
        );
    }
}
