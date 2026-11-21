<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Domains\Mail\Actions\CreateMailAccountAction;
use App\Domains\Mail\Actions\DeleteMailAccountAction;
use App\Domains\Mail\Actions\EnableMailDomainAction;
use App\Domains\Mail\Actions\IssueStandaloneMailTlsAction;
use App\Domains\Mail\Actions\UpdateMailAccountAction;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\DnsTxtVerificationToken;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Mail\ValueObjects\MailQuotaMb;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\CreateMailAccountRequest;
use App\Http\Requests\Mail\EnableMailDomainRequest;
use App\Http\Requests\Mail\UpdateMailAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MailAccountController extends Controller
{
    public function index(Request $request, ModuleRegistryQueryInterface $moduleRegistry): Response
    {
        $user = $request->user();

        $mailDomainsQuery = MailDomain::query()->orderBy('domain');
        if (! $user->isAdmin()) {
            $mailDomainsQuery->where('user_id', $user->id);
        }
        $mailDomains = $mailDomainsQuery->get();

        $accounts = MailAccount::query()
            ->with('mailDomain:id,domain,user_id')
            ->whereIn('mail_domain_id', $mailDomains->pluck('id'))
            ->orderBy('email')
            ->get();

        return Inertia::render('Mail/Accounts', [
            'mailCoreActive' => $moduleRegistry->isActive('mail_core'),
            'mailDomains' => $mailDomains,
            'accounts' => $accounts,
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function enableDomain(EnableMailDomainRequest $request, EnableMailDomainAction $action): RedirectResponse
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
        );

        return back()->with('success', "Mail enabled for {$domain->value}.");
    }

    public function dnsVerificationChallenge(Request $request): JsonResponse
    {
        Gate::authorize('create', MailDomain::class);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        try {
            $domain = new DomainName((string) $validated['domain']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        $token = DnsTxtVerificationToken::forUserAndDomain(
            $request->user()->id,
            $domain->value,
            (string) config('app.key'),
        );

        return response()->json([
            'domain' => $domain->value,
            'record_type' => 'TXT',
            'record_name' => DnsTxtVerificationToken::recordName($domain->value),
            'value' => $token->value,
        ]);
    }

    public function issueStandaloneTls(
        Request $request,
        MailDomain $mailDomain,
        IssueStandaloneMailTlsAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $mailDomain);

        $action->execute($request->user(), $mailDomain);

        return back()->with('success', "TLS certificate issued for mail.{$mailDomain->domain} and SNI sync queued.");
    }

    public function store(CreateMailAccountRequest $request, CreateMailAccountAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $mailDomain = MailDomain::findOrFail((int) $validated['mail_domain_id']);
        Gate::authorize('update', $mailDomain);

        try {
            $email = EmailAddress::fromParts((string) $validated['local_part'], $mailDomain->domain);
            $quota = new MailQuotaMb((int) $validated['quota_mb']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['local_part' => $e->getMessage()]);
        }

        $action->execute(
            actor: $request->user(),
            mailDomain: $mailDomain,
            email: $email,
            password: (string) $validated['password'],
            quotaMb: $quota,
            allowImap: (bool) ($validated['allow_imap'] ?? true),
            allowPop3: (bool) ($validated['allow_pop3'] ?? true),
            allowSmtp: (bool) ($validated['allow_smtp'] ?? true),
        );

        return back()->with('success', "Mail account {$email->value} created.");
    }

    public function update(
        UpdateMailAccountRequest $request,
        MailAccount $mailAccount,
        UpdateMailAccountAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $quota = null;
        if (array_key_exists('quota_mb', $validated)) {
            try {
                $quota = new MailQuotaMb((int) $validated['quota_mb']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['quota_mb' => $e->getMessage()]);
            }
        }

        $action->execute(
            actor: $request->user(),
            account: $mailAccount,
            password: isset($validated['password']) && $validated['password'] !== '' ? (string) $validated['password'] : null,
            quotaMb: $quota,
            active: array_key_exists('active', $validated) ? (bool) $validated['active'] : null,
            allowImap: array_key_exists('allow_imap', $validated) ? (bool) $validated['allow_imap'] : null,
            allowPop3: array_key_exists('allow_pop3', $validated) ? (bool) $validated['allow_pop3'] : null,
            allowSmtp: array_key_exists('allow_smtp', $validated) ? (bool) $validated['allow_smtp'] : null,
        );

        return back()->with('success', "Mail account {$mailAccount->email} updated.");
    }

    public function destroy(
        Request $request,
        MailAccount $mailAccount,
        DeleteMailAccountAction $action,
    ): RedirectResponse {
        Gate::authorize('delete', $mailAccount);

        $email = $mailAccount->email;
        $action->execute($request->user(), $mailAccount);

        return back()->with('success', "Mail account {$email} deleted and queued for purge.");
    }
}
