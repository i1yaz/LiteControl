<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\ApplyPdnsConfigAction;
use App\Domains\Dns\Actions\FlushPdnsCacheAction;
use App\Domains\Dns\Actions\RotatePdnsApiKeyAction;
use App\Domains\Dns\Actions\TestPdnsConnectionAction;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\Queries\GetPdnsHealthQuery;
use App\Domains\Dns\Queries\ListAutoprimariesQuery;
use App\Exceptions\System\LcCliException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\UpdatePdnsConfigRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PdnsConfigController extends Controller
{
    /**
     * Render the PowerDNS Daemon & HTTP API Configuration UI.
     */
    public function index(
        Request $request,
        GetPdnsHealthQuery $healthQuery,
        ListAutoprimariesQuery $listAutoprimariesQuery,
    ): Response {
        $setting = PdnsSetting::current();
        $health = $healthQuery->handle($setting);

        $autoprimaries = [];
        $autoprimariesError = null;

        if ($setting->hasApiKey() && ($health['api_health']['connected'] ?? false)) {
            try {
                $autoprimaries = $listAutoprimariesQuery->handle();
            } catch (PowerDnsException $e) {
                $autoprimariesError = $e->getMessage();
            }
        }

        return Inertia::render('Dns/Settings', [
            'settings' => $setting->toPublicArray(),
            'daemon' => $health['daemon'],
            'apiHealth' => $health['api_health'],
            'autoprimaries' => $autoprimaries,
            'autoprimariesError' => $autoprimariesError,
        ]);
    }

    /**
     * Validate and apply PowerDNS daemon & HTTP API configuration.
     */
    public function update(UpdatePdnsConfigRequest $request, ApplyPdnsConfigAction $action): RedirectResponse
    {
        try {
            $config = $request->toValueObject();
            $action->handle($config, $request->user(), $request->ip());

            return redirect()->back()->with('success', 'PowerDNS daemon and HTTP API configuration validated and applied.');
        } catch (InvalidArgumentException|LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Atomically rotate the PowerDNS HTTP API key with post-restart health probe and rollback.
     */
    public function rotateKey(Request $request, RotatePdnsApiKeyAction $action): RedirectResponse
    {
        try {
            $action->handle($request->user(), $request->ip());

            return redirect()->back()->with('success', 'PowerDNS API key rotated and verified via live health check.');
        } catch (LcCliException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Probe PowerDNS HTTP API connectivity and read active backend configuration.
     */
    public function testConnection(Request $request, TestPdnsConnectionAction $action): RedirectResponse
    {
        try {
            $result = $action->handle($request->user(), $request->ip());

            return redirect()->back()->with(
                'success',
                sprintf(
                    'Connected to PowerDNS %s v%s (active backend: %s).',
                    $result['server']['daemon_type'],
                    $result['server']['version'],
                    $result['backend']['launch'],
                )
            );
        } catch (PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Flush the global PowerDNS authoritative packet/query cache (`PUT /api/v1/servers/localhost/cache/flush?domain=.`).
     */
    public function flushCache(Request $request, FlushPdnsCacheAction $action): RedirectResponse
    {
        try {
            $domain = trim((string) $request->input('domain', '.'));
            $result = $action->handle($domain, $request->user(), $request->ip());

            return redirect()->back()->with(
                'success',
                sprintf(
                    'PowerDNS cache flushed for %s (%d entries cleared).',
                    $result['domain'] === '.' ? 'all zones (.)' : $result['domain'],
                    $result['count'],
                )
            );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
