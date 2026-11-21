<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\UpdateDomainSpamPolicyAction;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\SpamPolicy;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\UpdateDomainSpamPolicyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MailSpamFilterController extends Controller
{
    public function index(Request $request, ModuleRegistryQueryInterface $moduleRegistry): Response
    {
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

        if ($selectedDomainModel !== null) {
            Gate::authorize('view', $selectedDomainModel);
        }

        $mapDomain = static fn (MailDomain $mailDomain): array => [
            'id' => $mailDomain->id,
            'domain' => $mailDomain->domain,
            'active' => (bool) $mailDomain->active,
            'spam_filter_enabled' => (bool) $mailDomain->spam_filter_enabled,
            'spam_header_score' => (float) ($mailDomain->spam_header_score ?? 5.0),
            'spam_reject_score' => (float) ($mailDomain->spam_reject_score ?? 15.0),
        ];

        $domains = $mailDomains->map($mapDomain)->values()->all();
        $selectedDomain = $selectedDomainModel !== null ? $mapDomain($selectedDomainModel) : null;

        return Inertia::render('Mail/Spam/Index', [
            'filter_active' => $moduleRegistry->isActive('mail_filter'),
            'clamav_active' => $moduleRegistry->isActive('clamav'),
            'mail_core_active' => $moduleRegistry->isActive('mail_core'),
            'can_manage_modules' => $user->isAdmin(),
            'domains' => $domains,
            'selected_domain' => $selectedDomain,
        ]);
    }

    public function update(
        UpdateDomainSpamPolicyRequest $request,
        MailDomain $mailDomain,
        UpdateDomainSpamPolicyAction $action,
    ): RedirectResponse {
        $policy = SpamPolicy::fromArray($request->validated());

        $action->execute($request->user(), $mailDomain, $policy);

        return redirect()->back()->with('success', "Anti-spam policy updated for {$mailDomain->domain}.");
    }
}
