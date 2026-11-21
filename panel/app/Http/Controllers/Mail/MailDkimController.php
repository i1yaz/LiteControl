<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\GenerateDkimKeyAction;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Queries\RequiredMailDnsRecordsQuery;
use App\Domains\Mail\ValueObjects\DkimSelector;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
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

        $mailDomainsQuery = MailDomain::query()
            ->with(['dkimKeys' => fn ($q) => $q->orderByDesc('id')])
            ->orderBy('domain');

        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }

        $mailDomains = $mailDomainsQuery->get();

        $dnsRecordsByDomain = [];
        foreach ($mailDomains as $mailDomain) {
            $activeKey = $mailDomain->dkimKeys->firstWhere('status', 'active');
            $dnsRecordsByDomain[$mailDomain->id] = $dnsRecordsQuery->execute(
                mailDomain: $mailDomain,
                activeDkimKey: $activeKey,
            );
        }

        return Inertia::render('Mail/Dkim', [
            'mailCoreActive' => $moduleRegistry->isActive('mail_core'),
            'powerDnsActive' => $moduleRegistry->isActive('powerdns'),
            'mailDomains' => $mailDomains,
            'dnsRecordsByDomain' => $dnsRecordsByDomain,
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

        $action->execute(
            actor: $request->user(),
            mailDomain: $mailDomain,
            selector: $selector,
            algorithm: $algorithm,
        );

        return back()->with('success', "DKIM key '{$selector->value}' ({$algorithm}) generated for {$mailDomain->domain}.");
    }
}
