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
use Inertia\Inertia;
use Inertia\Response;

class MailSpamFilterController extends Controller
{
    public function index(Request $request, ModuleRegistryQueryInterface $moduleRegistry): Response
    {
        $user = $request->user();

        $domains = MailDomain::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('domain')
            ->get([
                'id',
                'domain',
                'active',
                'spam_filter_enabled',
                'spam_header_score',
                'spam_reject_score',
            ]);

        return Inertia::render('Mail/SpamFilter', [
            'domains' => $domains,
            'filterActive' => $moduleRegistry->isActive('mail_filter'),
            'clamavActive' => $moduleRegistry->isActive('clamav'),
        ]);
    }

    public function update(
        UpdateDomainSpamPolicyRequest $request,
        MailDomain $mailDomain,
        UpdateDomainSpamPolicyAction $action,
    ): RedirectResponse {
        $policy = SpamPolicy::fromArray($request->validated());

        $action->execute($request->user(), $mailDomain, $policy);

        return redirect()->back()->with('success', "Spam filter policy updated for {$mailDomain->domain}.");
    }
}
