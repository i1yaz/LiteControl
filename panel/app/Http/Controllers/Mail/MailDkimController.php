<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\GenerateDkimKeyAction;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Queries\RequiredMailDnsRecordsQuery;
use App\Domains\Mail\ValueObjects\DkimSelector;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Exceptions\LcCliException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\GenerateDkimKeyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MailDkimController extends Controller
{
    public function index(
        Request $request,
        ModuleRegistryQueryInterface $moduleRegistry,
        RequiredMailDnsRecordsQuery $dnsRecordsQuery,
    ): Response {
        $user = $request->user();

        $mailDomainsQuery = MailDomain::query()->orderBy('domain');

        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }

        $mailDomains = $mailDomainsQuery->get();

        $domainFilter = strtolower(trim((string) $request->query('domain', '')));
        /** @var MailDomain|null $selectedDomainModel */
        $selectedDomainModel = $domainFilter !== ''
            ? $mailDomains->firstWhere('domain', $domainFilter)
            : $mailDomains->first();

        if ($selectedDomainModel === null && $mailDomains->isNotEmpty()) {
            $selectedDomainModel = $mailDomains->first();
        }

        $dkimKeys = [];
        $dnsRecords = [];

        if ($selectedDomainModel !== null) {
            Gate::authorize('view', $selectedDomainModel);

            $keysCollection = $selectedDomainModel->dkimKeys()
                ->orderByDesc('id')
                ->get();

            $dkimKeys = $keysCollection->map(fn (MailDkimKey $key): array => [
                'id' => $key->id,
                'selector' => $key->selector,
                'algorithm' => $key->algorithm,
                'public_key_txt' => $key->public_key_txt,
                'status' => $key->status,
                'dns_verified_at' => $key->dns_verified_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
                'rotated_at' => $key->rotated_at?->toIso8601String(),
            ])->values()->all();

            $activeKey = $keysCollection->firstWhere('status', 'active');
            $rawRecords = $dnsRecordsQuery->execute(
                mailDomain: $selectedDomainModel,
                activeDkimKey: $activeKey,
            );

            $dnsRecords = array_map(static function (array $record): array {
                $mapped = [
                    'type' => (string) $record['type'],
                    'host' => (string) $record['name'],
                    'value' => (string) $record['value'],
                    'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
                    'purpose' => (string) $record['purpose'],
                    'ttl' => (int) ($record['ttl'] ?? 3600),
                ];

                if (isset($record['chunks']) && is_array($record['chunks'])) {
                    $mapped['chunks'] = array_values($record['chunks']);
                }

                return $mapped;
            }, $rawRecords);
        }

        $domains = $mailDomains->map(fn (MailDomain $mailDomain): array => [
            'id' => $mailDomain->id,
            'domain' => $mailDomain->domain,
            'active' => (bool) $mailDomain->active,
            'dkim_enabled' => (bool) $mailDomain->dkim_enabled,
        ])->values()->all();

        $selectedDomain = $selectedDomainModel !== null ? [
            'id' => $selectedDomainModel->id,
            'domain' => $selectedDomainModel->domain,
            'active' => (bool) $selectedDomainModel->active,
            'dkim_enabled' => (bool) $selectedDomainModel->dkim_enabled,
        ] : null;

        return Inertia::render('Mail/Dkim/Index', [
            'domains' => $domains,
            'selected_domain' => $selectedDomain,
            'dkim_keys' => $dkimKeys,
            'dns_records' => $dnsRecords,
            'powerdns_active' => $moduleRegistry->isActive('dns'),
            'mail_core_active' => $moduleRegistry->isActive('mail_core'),
        ]);
    }

    public function store(GenerateDkimKeyRequest $request, GenerateDkimKeyAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $mailDomain = MailDomain::findOrFail((int) $validated['mail_domain_id']);
        Gate::authorize('update', $mailDomain);

        try {
            $selector = new DkimSelector((string) $validated['selector']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['selector' => $e->getMessage()]);
        }

        $algorithm = (string) ($validated['algorithm'] ?? 'rsa2048');

        try {
            $action->execute(
                actor: $request->user(),
                mailDomain: $mailDomain,
                selector: $selector,
                algorithm: $algorithm,
            );
        } catch (LcCliException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "DKIM key '{$selector->value}' ({$algorithm}) generated for {$mailDomain->domain}.");
    }
}
