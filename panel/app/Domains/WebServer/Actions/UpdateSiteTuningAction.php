<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\SiteTuning;
use App\Domains\WebServer\ValueObjects\SiteTuningConfig;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSiteTuningAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver
    ) {}

    /**
     * @throws LcCliException
     */
    public function handle(Domain $domain, SiteTuningConfig $config, User $actor, ?string $ipAddress = null): SiteTuning
    {
        $domain->loadMissing('user');
        $unixUser = $domain->user->username ?? 'nobody';
        $phpVersion = $domain->php_version ?? '83';

        try {
            $this->vhostDriver->applyTuning($domain->domain, $unixUser, $phpVersion, $config);
        } catch (LcCliException $e) {
            Log::error('Failed to apply per-site tuning to OpenLiteSpeed', [
                'domain' => $domain->domain,
                'actor_id' => $actor->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $tuning = DB::transaction(function () use ($domain, $config, $actor, $ipAddress): SiteTuning {
            $tuning = SiteTuning::updateOrCreate(
                ['domain_id' => $domain->id],
                array_merge($config->toArray(), [
                    'sync_status' => 'applied',
                    'last_error' => null,
                    'applied_at' => now(),
                ])
            );

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'site_tuning.updated',
                'command' => "vhost apply-tuning {$domain->domain}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => $config->toArray(),
            ]);

            return $tuning;
        });

        Log::info('Per-site tuning updated and applied', [
            'domain' => $domain->domain,
            'actor_id' => $actor->id,
            'max_conns' => $config->maxConns,
            'php_memory_limit_mb' => $config->phpMemoryLimitMb,
        ]);

        return $tuning;
    }
}
