<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployment;

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\WebServer\Models\Domain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\StoreDeploymentHookRequest;
use Illuminate\Http\RedirectResponse;

class DomainDeploymentHookController extends Controller
{
    public function store(StoreDeploymentHookRequest $request, Domain $domain): RedirectResponse
    {
        $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();

        $config->hooks()->create($request->validated());

        return back()->with('success', 'Deployment hook added.');
    }

    public function update(StoreDeploymentHookRequest $request, Domain $domain, DomainDeploymentHook $hook): RedirectResponse
    {
        $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();
        abort_unless($hook->config_id === $config->id, 404);

        $hook->update($request->validated());

        return back()->with('success', 'Deployment hook updated.');
    }

    public function destroy(Domain $domain, DomainDeploymentHook $hook): RedirectResponse
    {
        $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();
        abort_unless($hook->config_id === $config->id, 404);

        $hook->delete();

        return back()->with('success', 'Deployment hook deleted.');
    }
}
