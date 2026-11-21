<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\FlushPdnsCacheAction;
use App\Domains\Dns\Actions\RectifyZoneAction;
use App\Domains\Dns\Actions\TriggerAxfrRetrieveAction;
use App\Domains\Dns\Actions\TriggerNotifyAction;
use App\Domains\Dns\Actions\UpdateZoneModeAction;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\UpdateZoneModeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PdnsZoneModeController extends Controller
{
    /**
     * Switch a zone's replication operating mode (`Native`, `Primary`, `Secondary`) and transfer settings.
     */
    public function updateMode(
        UpdateZoneModeRequest $request,
        string $zone,
        UpdateZoneModeAction $action,
    ): RedirectResponse {
        try {
            $payload = $request->toValueObject($zone);
            $result = $action->handle($payload, $request->user(), $request->ip());

            $message = $result['rectified']
                ? sprintf(
                    'Operating mode for %s switched from %s to %s and zone rectified.',
                    $result['zone'],
                    $result['previous_kind'],
                    $result['kind']
                )
                : sprintf(
                    'Operating mode & transfer controls updated for %s (%s).',
                    $result['zone'],
                    $result['kind']
                );

            return redirect()->route('dns.zones.records.show', ['zone' => $result['zone']])
                ->with('success', $message);
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Trigger an outbound DNS NOTIFY (`PUT /api/v1/servers/localhost/zones/{zone_id}/notify`) for a Primary zone.
     */
    public function notify(
        Request $request,
        string $zone,
        TriggerNotifyAction $action,
    ): RedirectResponse {
        try {
            $result = $action->handle($zone, $request->user(), $request->ip());

            return redirect()->route('dns.zones.records.show', ['zone' => $result['zone']])
                ->with('success', sprintf('Outbound DNS NOTIFY sent for %s (%s).', $result['zone'], $result['result']));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Trigger an immediate AXFR/IXFR transfer retrieval (`PUT /api/v1/servers/localhost/zones/{zone_id}/axfr-retrieve`) for a Secondary zone.
     */
    public function axfrRetrieve(
        Request $request,
        string $zone,
        TriggerAxfrRetrieveAction $action,
    ): RedirectResponse {
        try {
            $result = $action->handle($zone, $request->user(), $request->ip());

            return redirect()->route('dns.zones.records.show', ['zone' => $result['zone']])
                ->with('success', sprintf('AXFR transfer retrieval queued for %s (%s).', $result['zone'], $result['result']));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Rectify a zone's ordername/auth records (`PUT /api/v1/servers/localhost/zones/{zone_id}/rectify`).
     */
    public function rectify(
        Request $request,
        string $zone,
        RectifyZoneAction $action,
    ): RedirectResponse {
        try {
            $result = $action->handle($zone, $request->user(), $request->ip());

            return redirect()->route('dns.zones.records.show', ['zone' => $result['zone']])
                ->with('success', sprintf('Zone %s rectified (%s).', $result['zone'], $result['result']));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Flush the PowerDNS packet/query cache for a specific zone (`PUT /api/v1/servers/localhost/cache/flush?domain={zone_id}`).
     */
    public function flushCache(
        Request $request,
        string $zone,
        FlushPdnsCacheAction $action,
    ): RedirectResponse {
        try {
            $result = $action->handle($zone, $request->user(), $request->ip());

            return redirect()->route('dns.zones.records.show', ['zone' => $result['domain']])
                ->with(
                    'success',
                    sprintf(
                        'PowerDNS cache flushed for %s (%d entries cleared).',
                        $result['domain'],
                        $result['count'],
                    )
                );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
