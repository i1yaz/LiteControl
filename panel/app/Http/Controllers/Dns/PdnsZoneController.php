<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\CreatePdnsZoneAction;
use App\Domains\Dns\Actions\DeletePdnsZoneAction;
use App\Domains\Dns\Actions\ExportPdnsZoneAction;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\Queries\ListDnsZoneTemplatesQuery;
use App\Domains\Dns\Queries\ListPdnsZonesQuery;
use App\Domains\Dns\Queries\SearchPdnsDataQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\CreatePdnsZoneRequest;
use App\Http\Requests\Dns\DeletePdnsZoneRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdnsZoneController extends Controller
{
    /**
     * Render the PowerDNS Zone Manager page with zone list and optional cross-zone search results.
     */
    public function index(
        Request $request,
        ListPdnsZonesQuery $listZonesQuery,
        SearchPdnsDataQuery $searchDataQuery,
        ListDnsZoneTemplatesQuery $listTemplatesQuery,
    ): Response {
        $setting = PdnsSetting::current();
        $searchQuery = trim((string) $request->query('q', ''));

        $zones = [];
        $searchResults = [];
        $apiError = null;

        try {
            $zones = $listZonesQuery->handle();

            if ($searchQuery !== '') {
                $searchResults = $searchDataQuery->handle($searchQuery, 100);
            }
        } catch (PowerDnsException $e) {
            $apiError = $e->getMessage();
        }

        return Inertia::render('Dns/Zones', [
            'zones' => $zones,
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
            'apiError' => $apiError,
            'defaultNameservers' => $this->extractDefaultNameservers($setting),
            'defaultNameserverDetails' => $setting->normalizedDefaultNameservers(),
            'zoneTemplates' => $listTemplatesQuery->handle(),
            'capabilities' => [
                'has_api_key' => $setting->hasApiKey(),
                'primary_enabled' => (bool) $setting->primary_enabled,
                'secondary_enabled' => (bool) $setting->secondary_enabled,
                'autosecondary_enabled' => (bool) $setting->autosecondary_enabled,
            ],
        ]);
    }

    /**
     * Create a new forward or reverse DNS zone in PowerDNS.
     */
    public function store(CreatePdnsZoneRequest $request, CreatePdnsZoneAction $action): RedirectResponse
    {
        try {
            $payload = $request->toValueObject();
            $action->handle($payload, $request->user(), $request->ip());

            return redirect()->route('dns.zones.index')
                ->with('success', sprintf('DNS zone %s (%s) created.', $payload->name, $payload->kind));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Stream a zone export (`GET /api/v1/servers/localhost/zones/{zone_id}/export`) as a downloadable `.zone` file.
     */
    public function export(Request $request, string $zone, ExportPdnsZoneAction $action): StreamedResponse|RedirectResponse
    {
        try {
            $exported = $action->handle($zone, $request->user(), $request->ip());

            return response()->streamDownload(
                static function () use ($exported): void {
                    echo $exported['content'];
                },
                $exported['filename'],
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]
            );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->route('dns.zones.index')->with('error', $e->getMessage());
        }
    }

    /**
     * Delete a DNS zone after explicit name confirmation.
     */
    public function destroy(DeletePdnsZoneRequest $request, string $zone, DeletePdnsZoneAction $action): RedirectResponse
    {
        try {
            $confirmName = (string) $request->validated('confirm_name');
            $action->handle($zone, $confirmName, $request->user(), $request->ip());

            return redirect()->route('dns.zones.index')
                ->with('success', sprintf('DNS zone %s deleted.', $zone));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function extractDefaultNameservers(PdnsSetting $setting): array
    {
        $configured = $setting->default_nameservers ?? [];
        if (! is_array($configured)) {
            return [];
        }

        $hostnames = [];
        foreach ($configured as $entry) {
            $hostname = is_array($entry)
                ? trim((string) ($entry['hostname'] ?? ''))
                : (is_string($entry) ? trim($entry) : '');

            if ($hostname !== '') {
                $hostnames[] = $hostname;
            }
        }

        return array_values(array_unique($hostnames));
    }
}
