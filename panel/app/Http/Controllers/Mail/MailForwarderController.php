<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\CreateMailAliasAction;
use App\Domains\Mail\Actions\DeleteMailAliasAction;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\CreateMailAliasRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MailForwarderController extends Controller
{
    public function index(Request $request, ModuleRegistryQueryInterface $moduleRegistry): Response
    {
        $user = $request->user();

        $mailDomainsQuery = MailDomain::query()->orderBy('domain');
        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }
        $mailDomains = $mailDomainsQuery->get();

        $aliases = MailAlias::query()
            ->with('mailDomain:id,domain,user_id')
            ->whereIn('mail_domain_id', $mailDomains->pluck('id'))
            ->orderBy('source_email')
            ->orderBy('destination_email')
            ->get();

        return Inertia::render('Mail/Forwarders', [
            'mailCoreActive' => $moduleRegistry->isActive('mail_core'),
            'mailDomains' => $mailDomains,
            'aliases' => $aliases,
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
