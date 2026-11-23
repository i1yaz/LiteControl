<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployment;

use App\Domains\Deployment\Actions\GenerateDeployKeyAction;
use App\Domains\Deployment\Actions\RollbackDeploymentAction;
use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Services\DeploymentPresetService;
use App\Domains\WebServer\Models\Domain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\UpdateDeploymentConfigRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DomainDeploymentController extends Controller
{
    public function show(Domain $domain, DeploymentPresetService $presets): Response
    {
        $config = DomainDeploymentConfig::with('hooks')
            ->firstOrCreate(
                ['domain_id' => $domain->id],
                [
                    'webhook_token' => Str::random(40),
                    'php_version' => preg_replace('/[^0-9]/', '', (string) ($domain->php_version ?? '83')) ?: '83',
                ],
            );

        $deployments = DomainDeployment::query()
            ->where('domain_id', $domain->id)
            ->latest('id')
            ->limit(15)
            ->get();

        return Inertia::render('Domains/Deployment/Index', [
            'domain' => $domain,
            'config' => $config,
            'hooks' => $config->hooks,
            'deployments' => $deployments,
            'presets' => $presets->all(),
        ]);
    }

    public function updateConfig(UpdateDeploymentConfigRequest $request, Domain $domain): RedirectResponse
    {
        $validated = $request->validated();

        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domain->id],
            ['webhook_token' => Str::random(40)],
        );

        $wasSimple = $config->mode === 'simple';

        $updateData = collect($validated)->except(['api_token', 'webhook_secret', 'deploy_key_private', 'deploy_key_public'])->all();

        if (! empty($validated['api_token'])) {
            $updateData['api_token_encrypted'] = $validated['api_token'];
        }

        if (! empty($validated['webhook_secret'])) {
            $updateData['webhook_secret_encrypted'] = $validated['webhook_secret'];
        }

        if (! empty($validated['deploy_key_public'])) {
            $updateData['deploy_key_public'] = trim($validated['deploy_key_public']);
        }

        if (! empty($validated['deploy_key_private'])) {
            $updateData['deploy_key_private_encrypted'] = trim($validated['deploy_key_private']);
        }

        $config->update($updateData);

        if ($wasSimple && $config->mode === 'atomic') {
            $domain->loadMissing('user');
            $linuxUser = $domain->user->username ?? $domain->user->name ?? 'www-data';
            $cliPath = (string) env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');

            Process::timeout(60)->run([
                'sudo',
                $cliPath,
                'deploy',
                'init',
                "--user={$linuxUser}",
                "--domain={$domain->domain}",
            ]);
        }

        return back()->with('success', 'Deployment configuration updated.');
    }

    public function generateDeployKey(Domain $domain, GenerateDeployKeyAction $action): RedirectResponse
    {
        $action->execute($domain);

        return back()->with('success', 'SSH deploy key generated. Add the public key to your Git repository.');
    }

    public function deploy(Domain $domain): RedirectResponse
    {
        $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();

        if ($config->mode !== 'atomic') {
            return back()->withErrors(['mode' => 'Switch deployment mode to Atomic before triggering an atomic deployment.']);
        }

        $deployment = DomainDeployment::create([
            'domain_id' => $domain->id,
            'release_id' => now()->format('Ymd_His'),
            'branch' => $config->branch,
            'trigger_type' => 'manual',
            'triggered_by' => auth()->id(),
            'status' => 'queued',
        ]);

        RunAtomicDeploymentJob::dispatch($config, $deployment);

        return back()->with('success', "Deployment {$deployment->release_id} queued.");
    }

    public function rollback(Domain $domain, DomainDeployment $deployment, RollbackDeploymentAction $action): RedirectResponse
    {
        abort_unless($deployment->domain_id === $domain->id, 404);

        $action->execute($domain, $deployment);

        return back()->with('success', "Rolled back to release {$deployment->release_id}.");
    }

    public function cancel(Domain $domain, DomainDeployment $deployment): RedirectResponse
    {
        abort_unless($deployment->domain_id === $domain->id, 404);

        if (! in_array($deployment->status, ['queued', 'running'], true)) {
            return back()->with('success', "Deployment {$deployment->release_id} is already {$deployment->status}.");
        }

        $cancelDir = storage_path("app/deployments/domain_{$domain->id}");
        File::ensureDirectoryExists($cancelDir, 0750);
        File::put("{$cancelDir}/cancel_{$deployment->release_id}", (string) now()->toIso8601String());

        $summary = 'Deployment cancelled by user.';
        if ($deployment->log_path && File::exists($deployment->log_path)) {
            File::append($deployment->log_path, sprintf("[%s] %s\n", now()->format('Y-m-d H:i:s'), $summary));
        }

        $deployment->update([
            'status' => 'failed',
            'error_summary' => $summary,
            'finished_at' => now(),
        ]);

        // Release ShouldBeUnique lock so a new deployment can be triggered immediately.
        Cache::lock('laravel_unique_job:'.RunAtomicDeploymentJob::class.':'.$domain->id)->forceRelease();

        return back()->with('success', "Deployment {$deployment->release_id} cancelled.");
    }

    public function log(Domain $domain, DomainDeployment $deployment): JsonResponse
    {
        abort_unless($deployment->domain_id === $domain->id, 404);

        $content = '';
        if ($deployment->log_path && File::exists($deployment->log_path)) {
            $content = File::get($deployment->log_path);
        }

        return response()->json([
            'status' => $deployment->status,
            'log' => $content,
        ]);
    }
}
