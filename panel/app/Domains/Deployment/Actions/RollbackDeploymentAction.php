<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class RollbackDeploymentAction
{
    /**
     * Roll back a domain's active release to a target historical release via lc-cli
     * and record the rollback in domain_deployments.
     */
    public function execute(Domain $domain, DomainDeployment $targetDeployment): DomainDeployment
    {
        $domain->loadMissing('user');
        $config = DomainDeploymentConfig::where('domain_id', $domain->id)->first();

        $linuxUser = $domain->user->username ?? $domain->user->name ?? 'www-data';
        $phpVersion = preg_replace('/[^0-9]/', '', (string) ($config?->php_version ?? $domain->php_version ?? '83')) ?: '83';
        $phpBin = "/usr/local/lsws/lsphp{$phpVersion}/bin/php";

        $cliPath = (string) env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');

        $result = Process::timeout(120)->run([
            'sudo',
            $cliPath,
            'deploy',
            'rollback',
            "--user={$linuxUser}",
            "--domain={$domain->domain}",
            "--release={$targetDeployment->release_id}",
            "--php={$phpBin}",
        ]);

        if (! $result->successful()) {
            $error = trim($result->errorOutput() ?: $result->output()) ?: "Rollback failed with exit code {$result->exitCode()}.";

            throw new RuntimeException($error);
        }

        return DB::transaction(function () use ($domain, $targetDeployment): DomainDeployment {
            DomainDeployment::query()
                ->where('domain_id', $domain->id)
                ->where('status', 'active')
                ->where('id', '!=', $targetDeployment->id)
                ->update(['status' => 'superseded']);

            $targetDeployment->update(['status' => 'active']);

            return DomainDeployment::create([
                'domain_id' => $domain->id,
                'release_id' => $targetDeployment->release_id,
                'commit_sha' => $targetDeployment->commit_sha,
                'commit_message' => $targetDeployment->commit_message,
                'commit_author' => $targetDeployment->commit_author,
                'trigger_type' => 'rollback',
                'status' => 'superseded',
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        });
    }
}
