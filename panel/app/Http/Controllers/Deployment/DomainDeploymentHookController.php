<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployment;

use App\Domains\Deployment\Actions\CreateDeploymentHookAction;
use App\Domains\Deployment\Actions\GetDeploymentPipelineAction;
use App\Domains\Deployment\Actions\ReorderDeploymentHooksAction;
use App\Domains\Deployment\Actions\UpdateDeploymentHookAction;
use App\Domains\Deployment\Actions\UpdateInstallScriptAction;
use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\WebServer\Models\Domain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\ReorderDeploymentHooksRequest;
use App\Http\Requests\Deployment\StoreDeploymentHookRequest;
use App\Http\Requests\Deployment\UpdateDeploymentHookRequest;
use App\Http\Requests\Deployment\UpdateInstallScriptRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DomainDeploymentHookController extends Controller
{
    public function pipeline(
        Request $request,
        Domain $domain,
        GetDeploymentPipelineAction $action,
    ): JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = $this->resolveConfig($domain);

        return response()->json($action->execute($config));
    }

    public function updateInstallScript(
        UpdateInstallScriptRequest $request,
        Domain $domain,
        UpdateInstallScriptAction $action,
        GetDeploymentPipelineAction $getPipeline,
    ): RedirectResponse|JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = $this->resolveConfig($domain);

        $updated = $action->execute($config, $request->validated('install_script'));

        if ($request->expectsJson()) {
            return response()->json([
                'install_script' => $updated->install_script,
                'pipeline' => $getPipeline->execute($updated),
            ]);
        }

        return back()->with('success', 'Stage 2 install script updated.');
    }

    public function store(
        StoreDeploymentHookRequest $request,
        Domain $domain,
        CreateDeploymentHookAction $action,
    ): RedirectResponse|JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = $this->resolveConfig($domain);
        $validated = $request->validated();

        $slot = isset($validated['slot']) && is_string($validated['slot'])
            ? DeploymentHookSlot::from($validated['slot'])
            : null;

        $hook = $action->execute(
            config: $config,
            name: (string) $validated['name'],
            script: (string) $validated['script'],
            slot: $slot,
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        );

        if ($request->expectsJson()) {
            return response()->json(['hook' => $hook], 201);
        }

        return back()->with('success', 'Deployment hook added.');
    }

    public function update(
        UpdateDeploymentHookRequest $request,
        Domain $domain,
        DomainDeploymentHook $hook,
        UpdateDeploymentHookAction $action,
    ): RedirectResponse|JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = $this->resolveConfig($domain);
        $validated = $request->validated();

        $slot = isset($validated['slot']) && is_string($validated['slot'])
            ? DeploymentHookSlot::from($validated['slot'])
            : null;

        $updated = $action->execute(
            config: $config,
            hook: $hook,
            name: (string) $validated['name'],
            script: (string) $validated['script'],
            slot: $slot,
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
        );

        if ($request->expectsJson()) {
            return response()->json(['hook' => $updated]);
        }

        return back()->with('success', 'Deployment hook updated.');
    }

    public function reorder(
        ReorderDeploymentHooksRequest $request,
        Domain $domain,
        ReorderDeploymentHooksAction $action,
        GetDeploymentPipelineAction $getPipeline,
    ): RedirectResponse|JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = $this->resolveConfig($domain);

        /** @var list<array{id: int|string, slot: string, sort_order: int|string}> $hooks */
        $hooks = $request->validated('hooks', []);
        $action->execute($config, $hooks);

        if ($request->expectsJson()) {
            return response()->json([
                'pipeline' => $getPipeline->execute($config),
            ]);
        }

        return back()->with('success', 'Deployment pipeline order updated.');
    }

    public function destroy(
        Request $request,
        Domain $domain,
        DomainDeploymentHook $hook,
    ): RedirectResponse|JsonResponse {
        $this->authorizeDomainAccess($request, $domain);
        $config = DomainDeploymentConfig::query()->where('domain_id', $domain->id)->firstOrFail();
        abort_unless((int) $hook->config_id === (int) $config->id, 404);

        $hook->delete();

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'Deployment hook deleted.');
    }

    private function authorizeDomainAccess(Request $request, Domain $domain): void
    {
        $user = $request->user();
        abort_unless(
            $user !== null && ($user->isAdmin() || (int) $domain->user_id === (int) $user->id),
            403,
        );
    }

    private function resolveConfig(Domain $domain): DomainDeploymentConfig
    {
        return DomainDeploymentConfig::query()->firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'webhook_token' => Str::random(40),
                'php_version' => preg_replace('/[^0-9]/', '', (string) ($domain->php_version ?? '83')) ?: '83',
            ],
        );
    }
}
