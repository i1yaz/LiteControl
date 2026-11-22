<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\EnableMailDomainAction;
use App\Domains\Mail\Actions\UpdateMailDomainAction;
use App\Domains\Mail\Contracts\WebmailDriverInterface;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Domains\WebServer\Models\Domain as WebDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\EnableMailDomainRequest;
use App\Http\Requests\Mail\UpdateMailDomainRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MailDomainController extends Controller
{
    public function index(
        Request $request,
        ModuleRegistryQueryInterface $moduleRegistry,
        WebmailDriverInterface $webmailDriver,
    ): Response {
        Gate::authorize('viewAny', MailDomain::class);

        $user = $request->user();
        $webmailActive = $moduleRegistry->isActive('mail_webmail');

        $mailDomainsQuery = MailDomain::query()
            ->withCount('accounts')
            ->orderBy('domain');

        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }

        $mailDomains = $mailDomainsQuery->get();

        $domains = $mailDomains->map(function (MailDomain $mailDomain) use ($webmailActive, $webmailDriver): array {
            $webmailUrl = null;
            if ($webmailActive) {
                $webmailUrl = $webmailDriver->loginUrl(new DomainName($mailDomain->domain));
            }

            return [
                'id' => $mailDomain->id,
                'domain' => $mailDomain->domain,
                'ownership_source' => $mailDomain->ownership_source,
                'active' => (bool) $mailDomain->active,
                'default_quota_mb' => (int) $mailDomain->default_quota_mb,
                'max_accounts' => (int) $mailDomain->max_accounts,
                'accounts_count' => (int) $mailDomain->accounts_count,
                'dkim_enabled' => (bool) $mailDomain->dkim_enabled,
                'outbound_hourly_limit' => (int) ($mailDomain->outbound_hourly_limit ?? 200),
                'tls_sni_synced_at' => $mailDomain->tls_sni_synced_at?->toIso8601String(),
                'webmail_url' => $webmailUrl,
            ];
        })->values()->all();

        $enabledDomainNames = MailDomain::query()->pluck('domain')->all();

        $availableWebDomainsQuery = WebDomain::query()
            ->where('user_id', $user->id)
            ->orderBy('domain');

        if ($enabledDomainNames !== []) {
            $availableWebDomainsQuery->whereNotIn('domain', $enabledDomainNames);
        }

        $availableWebDomains = $availableWebDomainsQuery
            ->pluck('domain')
            ->values()
            ->all();

        $pendingPurgesQuery = MailPendingPurge::query()
            ->where('entity_type', 'domain')
            ->orderByDesc('created_at');

        if (! $user->isAdmin()) {
            $userDomainNames = WebDomain::query()
                ->where('user_id', $user->id)
                ->pluck('domain')
                ->merge($mailDomains->pluck('domain'))
                ->unique()
                ->values()
                ->all();

            $pendingPurgesQuery->whereIn('domain', $userDomainNames);
        }

        $pendingPurges = $pendingPurgesQuery
            ->get()
            ->map(fn (MailPendingPurge $purge): array => [
                'id' => $purge->id,
                'entity_type' => $purge->entity_type,
                'domain' => $purge->domain,
                'email' => $purge->email,
                'status' => $purge->status,
                'attempts' => (int) $purge->attempts,
                'last_error' => $purge->last_error,
            ])
            ->values()
            ->all();

        return Inertia::render('Mail/Domains/Index', [
            'domains' => $domains,
            'available_web_domains' => $availableWebDomains,
            'pending_purges' => $pendingPurges,
            'can_admin_override' => $user->isAdmin(),
            'mail_core_active' => $moduleRegistry->isActive('mail_core'),
        ]);
    }

    public function store(EnableMailDomainRequest $request, EnableMailDomainAction $action): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $domain = new DomainName((string) $validated['domain']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        $action->execute(
            actor: $request->user(),
            owner: $request->user(),
            domain: $domain,
            adminOverride: (bool) ($validated['admin_override'] ?? false),
            defaultQuotaMb: (int) ($validated['default_quota_mb'] ?? 1024),
            maxAccounts: (int) ($validated['max_accounts'] ?? 50),
            verifyDnsTxt: (bool) ($validated['verify_dns_txt'] ?? false),
            outboundHourlyLimit: (int) ($validated['outbound_hourly_limit'] ?? 200),
        );

        return back()->with('success', "Mail enabled for {$domain->value}.");
    }

    public function update(
        UpdateMailDomainRequest $request,
        MailDomain $mailDomain,
        UpdateMailDomainAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $action->execute(
            actor: $request->user(),
            mailDomain: $mailDomain,
            defaultQuotaMb: (int) $validated['default_quota_mb'],
            maxAccounts: (int) $validated['max_accounts'],
            outboundHourlyLimit: (int) $validated['outbound_hourly_limit'],
            active: (bool) $validated['active'],
        );

        return back()->with('success', "Mail domain {$mailDomain->domain} updated.");
    }
}
