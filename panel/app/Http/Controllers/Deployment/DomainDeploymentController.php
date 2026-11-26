<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployment;

use App\Domains\Deployment\Actions\GenerateDeployKeyAction;
use App\Domains\Deployment\Actions\GetDeploymentPipelineAction;
use App\Domains\Deployment\Actions\RollbackDeploymentAction;
use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use App\Domains\Deployment\Services\DeploymentPresetService;
use App\Domains\SourceControl\Actions\BindDomainSourceControlAction;
use App\Domains\SourceControl\Actions\EvaluatePanelUrlGateAction;
use App\Domains\SourceControl\Actions\SwitchDomainSourceModeAction;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\WebServer\Models\Domain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\UpdateDeploymentConfigRequest;
use App\Http\Requests\SourceControl\BindDomainSourceControlRequest;
use App\Http\Requests\SourceControl\SwitchSourceModeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DomainDeploymentController extends Controller
{
    public function show(
        Request $request,
        Domain $domain,
        DeploymentPresetService $presets,
        EvaluatePanelUrlGateAction $evaluateGate,
        GetDeploymentPipelineAction $getPipeline,
    ): Response {
        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'webhook_token' => Str::random(40),
                'php_version' => preg_replace('/[^0-9]/', '', (string) ($domain->php_version ?? '83')) ?: '83',
            ],
        );

        $deployments = DomainDeployment::query()
            ->where('domain_id', $domain->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(15)
            ->get();

        $gate = $evaluateGate->handle(requestHost: $request->getHost());

        $viewer = $request->user();
        $allowedOwnerIds = [(int) $domain->user_id];
        if ($viewer !== null && $viewer->isAdmin() && ! in_array((int) $viewer->id, $allowedOwnerIds, true)) {
            $allowedOwnerIds[] = (int) $viewer->id;
        }

        $providersQuery = GitProvider::query()
            ->whereIn('user_id', $allowedOwnerIds)
            ->where(function ($query) use ($config): void {
                $query->where('status', GitProviderStatus::Active->value);
                if ($config->git_provider_id !== null) {
                    $query->orWhere('id', $config->git_provider_id);
                }
            })
            ->latest('id')
            ->get();

        $providers = $providersQuery->map(function (GitProvider $provider): array {
            $accountLogin = $provider->external_account_login;
            $accountType = $provider->external_account_type;
            $isOrg = is_string($accountType) && strtolower($accountType) === 'organization' && is_string($accountLogin) && $accountLogin !== '';

            $manageReposUrl = null;
            if ($provider->installation_id !== null) {
                $manageReposUrl = $isOrg
                    ? "https://github.com/organizations/{$accountLogin}/settings/installations/{$provider->installation_id}"
                    : "https://github.com/settings/installations/{$provider->installation_id}";
            }

            return [
                'id' => $provider->id,
                'uuid' => $provider->uuid,
                'label' => $provider->label,
                'external_account_login' => $accountLogin,
                'external_account_type' => $accountType,
                'installation_id' => $provider->installation_id,
                'status' => $provider->status instanceof GitProviderStatus
                    ? $provider->status->value
                    : (string) $provider->status,
                'webhook_verified_at' => $provider->webhook_verified_at?->toIso8601String(),
                'last_webhook_at' => $provider->last_webhook_at?->toIso8601String(),
                'manage_repos_url' => $manageReposUrl,
            ];
        })->values()->all();

        $hasApiToken = ! empty($config->getRawOriginal('api_token_encrypted'));
        $hasDeployKey = ! empty($config->deploy_key_public) || ! empty($config->getRawOriginal('deploy_key_private_encrypted'));
        $hasWebhookSecret = ! empty($config->getRawOriginal('webhook_secret_encrypted'));
        $hasManualCredentials = $hasApiToken || $hasDeployKey || $hasWebhookSecret;

        $sourceMode = $config->source_mode instanceof SourceMode
            ? $config->source_mode->value
            : (string) ($config->source_mode ?: SourceMode::Manual->value);

        $isConfigured = $sourceMode === SourceMode::Provider->value
            ? ($config->git_provider_id !== null && ! empty($config->remote_repo_id))
            : (! empty($config->repo_url) || $hasManualCredentials);

        return Inertia::render('Domains/Deployment/Index', [
            'domain' => $domain,
            'config' => $config,
            'pipeline' => $getPipeline->execute($config),
            'deployments' => $deployments,
            'presets' => $presets->all(),
            'sourceControl' => [
                'gate' => $gate->toArray(),
                'panel_ssl_url' => "/security/panel-ssl?return_to=/domains/{$domain->id}/deploy",
                'providers' => $providers,
                'source_mode' => $sourceMode,
                'is_configured' => $isConfigured,
                'has_manual_credentials' => $hasManualCredentials,
                'has_api_token' => $hasApiToken,
                'has_deploy_key' => $hasDeployKey,
            ],
        ]);
    }

    public function bindSourceControl(
        BindDomainSourceControlRequest $request,
        Domain $domain,
        BindDomainSourceControlAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $provider = GitProvider::query()->findOrFail((int) $validated['git_provider_id']);

        Gate::authorize('bind', $provider);

        $deploymentSettings = collect($validated)
            ->only([
                'mode',
                'preset',
                'fetch_driver',
                'web_subpath',
                'php_version',
                'keep_releases',
                'shared_dirs',
                'shared_files',
                'health_check_enabled',
                'health_check_path',
                'auto_rollback',
            ])
            ->all();

        $result = $action->handle(
            actor: $request->user(),
            domainId: (int) $domain->id,
            domainUserId: (int) $domain->user_id,
            domainName: (string) $domain->domain,
            provider: $provider,
            remoteRepoId: (string) $validated['remote_repo_id'],
            remoteRepoFullName: (string) $validated['remote_repo_full_name'],
            branch: (string) $validated['branch'],
            autoDeploy: array_key_exists('auto_deploy', $validated) ? (bool) $validated['auto_deploy'] : true,
            confirmClearManualCredentials: (bool) ($validated['confirm_clear_credentials'] ?? false),
            deploymentSettings: $deploymentSettings,
            ipAddress: $request->ip(),
        );

        if ($result['was_simple_mode'] && (($deploymentSettings['mode'] ?? 'atomic') === 'atomic')) {
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

        return back()->with('success', 'Source Control provider binding saved.');
    }

    public function switchSourceMode(
        SwitchSourceModeRequest $request,
        Domain $domain,
        SwitchDomainSourceModeAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $targetMode = SourceMode::from((string) $validated['target_mode']);

        $provider = null;
        if (! empty($validated['git_provider_id'])) {
            $provider = GitProvider::query()->findOrFail((int) $validated['git_provider_id']);
            Gate::authorize('bind', $provider);
        }

        $result = $action->handle(
            actor: $request->user(),
            domainId: (int) $domain->id,
            domainUserId: (int) $domain->user_id,
            domainName: (string) $domain->domain,
            targetMode: $targetMode,
            confirmClearManualCredentials: (bool) ($validated['confirm_clear_credentials'] ?? false),
            provider: $provider,
            remoteRepoId: isset($validated['remote_repo_id']) ? (string) $validated['remote_repo_id'] : null,
            remoteRepoFullName: isset($validated['remote_repo_full_name']) ? (string) $validated['remote_repo_full_name'] : null,
            branch: isset($validated['branch']) ? (string) $validated['branch'] : null,
            autoDeploy: array_key_exists('auto_deploy', $validated) ? (bool) $validated['auto_deploy'] : true,
            ipAddress: $request->ip(),
        );

        $message = $targetMode === SourceMode::Manual
            ? 'Switched domain to Manual Git Mode.'
            : ($result['cleared_manual_credentials']
                ? 'Switched domain to Connected Provider Mode and cleared stored manual credentials.'
                : 'Switched domain to Connected Provider Mode.');

        return back()->with('success', $message);
    }

    public function updateConfig(
        UpdateDeploymentConfigRequest $request,
        Domain $domain,
        DeploymentPipelineService $pipelineService,
    ): RedirectResponse {
        $validated = $request->validated();

        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domain->id],
            ['webhook_token' => Str::random(40)],
        );

        $wasSimple = $config->mode === 'simple';

        $updateData = collect($validated)->except(['api_token', 'webhook_secret', 'deploy_key_private', 'deploy_key_public'])->all();

        if (array_key_exists('install_script', $validated)) {
            $updateData['install_script'] = $pipelineService->normalizeScript($validated['install_script']);
        }

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

        if ($config->source_mode === SourceMode::Provider && ($config->git_provider_id === null || empty($config->remote_repo_id))) {
            return back()->withErrors(['source_mode' => 'Select a connected Git provider and repository, then save your Source Control binding before deploying.']);
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
