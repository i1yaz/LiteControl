<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\CreateMailAliasAction;
use App\Domains\Mail\Actions\DeleteMailAliasAction;
use App\Domains\Mail\Actions\UpdateMailAliasAction;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\CreateMailAliasRequest;
use App\Http\Requests\Mail\UpdateMailAliasRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MailForwarderController extends Controller
{
    public function index(Request $request, ModuleRegistryQueryInterface $moduleRegistry): Response
    {
        Gate::authorize('viewAny', MailAlias::class);

        $user = $request->user();

        $mailDomainsQuery = MailDomain::query()->orderBy('domain');
        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }
        $mailDomains = $mailDomainsQuery->get();

        $domainFilter = trim((string) $request->query('domain', ''));
        /** @var MailDomain|null $selectedDomainModel */
        $selectedDomainModel = $domainFilter !== ''
            ? $mailDomains->firstWhere('domain', strtolower($domainFilter))
            : null;

        $typeFilter = strtolower(trim((string) $request->query('type', 'all')));
        if (! in_array($typeFilter, ['all', 'forwarders', 'catch_all'], true)) {
            $typeFilter = 'all';
        }

        $scopedDomainIds = $selectedDomainModel !== null
            ? [$selectedDomainModel->id]
            : $mailDomains->pluck('id')->all();

        $aliasesQuery = MailAlias::query()
            ->whereIn('mail_domain_id', $scopedDomainIds)
            ->orderBy('source_email')
            ->orderBy('destination_email');

        if ($typeFilter === 'forwarders') {
            $aliasesQuery->where('is_catch_all', false);
        } elseif ($typeFilter === 'catch_all') {
            $aliasesQuery->where('is_catch_all', true);
        }

        $aliases = $aliasesQuery
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MailAlias $alias): array => [
                'id' => $alias->id,
                'mail_domain_id' => $alias->mail_domain_id,
                'source_email' => $alias->source_email,
                'destination_email' => $alias->destination_email,
                'is_catch_all' => (bool) $alias->is_catch_all,
                'active' => (bool) $alias->active,
                'created_at' => $alias->created_at?->toIso8601String(),
            ]);

        $sourceCounts = MailAlias::query()
            ->select('source_email', DB::raw('COUNT(*) as total'))
            ->whereIn('mail_domain_id', $mailDomains->pluck('id'))
            ->groupBy('source_email')
            ->pluck('total', 'source_email')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $domains = $mailDomains->map(fn (MailDomain $mailDomain): array => [
            'id' => $mailDomain->id,
            'domain' => $mailDomain->domain,
            'active' => (bool) $mailDomain->active,
        ])->values()->all();

        $selectedDomain = $selectedDomainModel !== null ? [
            'id' => $selectedDomainModel->id,
            'domain' => $selectedDomainModel->domain,
            'active' => (bool) $selectedDomainModel->active,
        ] : null;

        return Inertia::render('Mail/Forwarders/Index', [
            'domains' => $domains,
            'selected_domain' => $selectedDomain,
            'aliases' => $aliases,
            'fanout_limit' => CreateMailAliasAction::DEFAULT_MAX_FAN_OUT,
            'source_counts' => $sourceCounts,
            'type_filter' => $typeFilter,
            'mail_core_active' => $moduleRegistry->isActive('mail_core'),
        ]);
    }

    public function store(CreateMailAliasRequest $request, CreateMailAliasAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $mailDomain = MailDomain::findOrFail((int) $validated['mail_domain_id']);
        Gate::authorize('update', $mailDomain);

        try {
            $destination = new EmailAddress((string) $validated['destination_email']);
            $alias = $action->execute(
                actor: $request->user(),
                mailDomain: $mailDomain,
                sourceEmailInput: (string) $validated['source_email'],
                destinationEmail: $destination,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['source_email' => $e->getMessage()]);
        }

        return back()->with('success', "Forwarder {$alias->source_email} -> {$alias->destination_email} created.");
    }

    public function update(
        UpdateMailAliasRequest $request,
        MailAlias $mailAlias,
        UpdateMailAliasAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $alias = $action->execute(
            actor: $request->user(),
            alias: $mailAlias,
            active: (bool) $validated['active'],
        );

        $state = $alias->active ? 'enabled' : 'disabled';

        return back()->with('success', "Forwarder {$alias->source_email} -> {$alias->destination_email} {$state}.");
    }

    public function destroy(
        Request $request,
        MailAlias $mailAlias,
        DeleteMailAliasAction $action,
    ): RedirectResponse {
        Gate::authorize('delete', $mailAlias);

        $action->execute($request->user(), $mailAlias);

        return back()->with('success', 'Mail forwarder deleted.');
    }
}
