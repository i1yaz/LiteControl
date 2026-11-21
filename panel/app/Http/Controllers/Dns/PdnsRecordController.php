<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\UpdateZoneRrsetsAction;
use App\Domains\Dns\Actions\UpdateZoneSoaAction;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Queries\GetPdnsZoneDetailQuery;
use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\RecordValidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\UpdateZoneRrsetsRequest;
use App\Http\Requests\Dns\UpdateZoneSoaRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PdnsRecordController extends Controller
{
    /**
     * Render the PowerDNS Zone Detail & Record Editor page.
     */
    public function show(
        Request $request,
        string $zone,
        GetPdnsZoneDetailQuery $zoneDetailQuery,
    ): Response|RedirectResponse {
        try {
            $zoneDetail = $zoneDetailQuery->handle($zone);
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->route('dns.zones.index')
                ->with('error', $e->getMessage());
        }

        $editableRecordTypes = array_values(array_filter(
            RecordNormalizer::SUPPORTED_TYPES,
            static fn (string $type): bool => $type !== 'SOA'
        ));

        return Inertia::render('Dns/ZoneRecords', [
            'zone' => $zoneDetail,
            'supportedTypes' => RecordNormalizer::SUPPORTED_TYPES,
            'editableRecordTypes' => $editableRecordTypes,
            'ttlPresets' => RecordValidator::TTL_PRESETS,
            'ttlBounds' => [
                'min' => RecordValidator::MIN_TTL,
                'max' => RecordValidator::MAX_TTL,
            ],
            'isReadOnly' => ($zoneDetail['kind'] ?? '') === 'Secondary',
        ]);
    }

    /**
     * Atomically apply one or more RRSet operations (`REPLACE`, `DELETE`, `EXTEND`, `PRUNE`) to a zone.
     */
    public function updateRrsets(
        UpdateZoneRrsetsRequest $request,
        string $zone,
        UpdateZoneRrsetsAction $action,
    ): RedirectResponse {
        try {
            /** @var list<array<string, mixed>> $operations */
            $operations = $request->validated('rrsets');
            $result = $action->handle($zone, $operations, $request->user(), $request->ip());

            $count = $result['operations_count'];

            return redirect()->route('dns.zones.records.show', ['zone' => $result['zone']])
                ->with(
                    'success',
                    $count === 1
                        ? sprintf('DNS record set updated in %s.', $result['zone'])
                        : sprintf('Applied %d staged DNS record set operations in %s.', $count, $result['zone'])
                );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Update a zone's SOA settings (`Primary NS`, `Hostmaster Email`, timers, TTL, and optional serial override).
     */
    public function updateSoa(
        UpdateZoneSoaRequest $request,
        string $zone,
        UpdateZoneSoaAction $action,
    ): RedirectResponse {
        try {
            $payload = $request->toValueObject($zone);
            $action->handle($payload, $request->user(), $request->ip());

            return redirect()->route('dns.zones.records.show', ['zone' => $payload->zoneId])
                ->with('success', sprintf('SOA settings updated for %s.', $payload->zoneId));
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
