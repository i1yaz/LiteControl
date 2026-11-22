<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\DeleteDnsZoneTemplateAction;
use App\Domains\Dns\Actions\RenderZoneTemplateAction;
use App\Domains\Dns\Actions\SetDefaultDnsZoneTemplateAction;
use App\Domains\Dns\Actions\UpdateDefaultNameserversAction;
use App\Domains\Dns\Actions\UpsertDnsZoneTemplateAction;
use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\Queries\ListDnsZoneTemplatesQuery;
use App\Domains\Dns\ValueObjects\ZoneTemplateRecordData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\UpdateDefaultNameserversRequest;
use App\Http\Requests\Dns\UpsertDnsZoneTemplateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PdnsTemplateController extends Controller
{
    /**
     * Render the PowerDNS Zone Templates & Default Nameservers management page.
     */
    public function index(ListDnsZoneTemplatesQuery $listTemplatesQuery): Response
    {
        $setting = PdnsSetting::current();

        return Inertia::render('Dns/Templates', [
            'templates' => $listTemplatesQuery->handle(),
            'defaultNameservers' => $setting->normalizedDefaultNameservers(),
            'supportedPlaceholders' => RenderZoneTemplateAction::SUPPORTED_PLACEHOLDERS,
            'allowedRecordTypes' => ZoneTemplateRecordData::ALLOWED_TYPES,
        ]);
    }

    /**
     * Create a new DNS zone template with its placeholder records.
     */
    public function store(UpsertDnsZoneTemplateRequest $request, UpsertDnsZoneTemplateAction $action): RedirectResponse
    {
        try {
            $payload = $request->toValueObject();
            $template = $action->handle($payload, null, $request->user(), $request->ip());

            return redirect()->route('dns.templates.index')
                ->with('success', sprintf('DNS zone template "%s" created.', $template->name));
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Update an existing DNS zone template and replace its records atomically.
     */
    public function update(
        UpsertDnsZoneTemplateRequest $request,
        DnsZoneTemplate $template,
        UpsertDnsZoneTemplateAction $action,
    ): RedirectResponse {
        try {
            $payload = $request->toValueObject();
            $updated = $action->handle($payload, $template, $request->user(), $request->ip());

            return redirect()->route('dns.templates.index')
                ->with('success', sprintf('DNS zone template "%s" updated.', $updated->name));
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark a DNS zone template as the default template for new zones.
     */
    public function setDefault(
        Request $request,
        DnsZoneTemplate $template,
        SetDefaultDnsZoneTemplateAction $action,
    ): RedirectResponse {
        $action->handle($template, $request->user(), $request->ip());

        return redirect()->route('dns.templates.index')
            ->with('success', sprintf('"%s" is now the default DNS zone template.', $template->name));
    }

    /**
     * Delete a DNS zone template.
     */
    public function destroy(
        Request $request,
        DnsZoneTemplate $template,
        DeleteDnsZoneTemplateAction $action,
    ): RedirectResponse {
        $name = (string) $template->name;
        $action->handle($template, $request->user(), $request->ip());

        return redirect()->route('dns.templates.index')
            ->with('success', sprintf('DNS zone template "%s" deleted.', $name));
    }

    /**
     * Save global default authoritative nameservers and their glue IPv4/IPv6 addresses.
     */
    public function updateNameservers(
        UpdateDefaultNameserversRequest $request,
        UpdateDefaultNameserversAction $action,
    ): RedirectResponse {
        try {
            $entries = $request->toValueObjects();
            $action->handle($entries, $request->user(), $request->ip());

            return redirect()->back()
                ->with('success', 'Default authoritative nameservers and glue IP addresses saved.');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
