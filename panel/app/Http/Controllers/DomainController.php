<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\LcCliService;
use App\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    protected LcCliService $lcCli;
    protected DomainService $domainService;

    public function __construct(LcCliService $lcCli, DomainService $domainService)
    {
        $this->lcCli = $lcCli;
        $this->domainService = $domainService;
    }

    /**
     * Display a listing of domains.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $query = Domain::with(['user:id,username,name', 'parentDomain:id,domain']);

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } else if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $domains = $query->latest()->paginate(20)->withQueryString();

        // Get supported PHP versions from lc-cli
        $phpListRes = $this->lcCli->run('php', ['list']);
        $phpVersions = ($phpListRes['status'] ?? '') === 'success' ? ($phpListRes['data'] ?? []) : [];

        return Inertia::render('Domains/Index', [
            'domains' => $domains,
            'phpVersions' => $phpVersions,
            'users' => $user->isAdmin() ? User::where('role', 'user')->get(['id', 'username', 'name']) : [],
        ]);
    }

    /**
     * Store a newly created domain or addon domain.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'domain' => [
                'required',
                'string',
                'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                'unique:domains,domain',
            ],
            'php_version' => ['nullable', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'type' => ['nullable', 'string', Rule::in(['primary', 'addon', 'subdomain'])],
            'public_path' => ['nullable', 'string', 'max:255'],
            'ssl_enabled' => ['nullable', 'boolean'],
        ];

        if ($user->isAdmin()) {
            $rules['user_id'] = ['required', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        $targetUser = $user->isAdmin() && !empty($validated['user_id'])
            ? User::with('package')->findOrFail($validated['user_id'])
            : $user->load('package');

        // Check package quota for non-admins
        if (! $user->isAdmin() && $targetUser->package) {
            $currentDomainCount = Domain::where('user_id', $targetUser->id)
                ->where('type', '!=', 'subdomain')
                ->count();

            if ($currentDomainCount >= $targetUser->package->max_domains) {
                return back()->withErrors([
                    'domain' => "Package limit reached: your package allows a maximum of {$targetUser->package->max_domains} domains.",
                ]);
            }
        }

        try {
            $res = $this->domainService->createDomain($targetUser, $validated, $user);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'domain' => 'VirtualHost creation failed: ' . $e->getMessage(),
            ]);
        }

        $redirect = redirect()->route('domains.index')->with('success', "Domain '{$validated['domain']}' added successfully.");
        if (!empty($res['warning'])) {
            $redirect->with('warning', $res['warning']);
        }

        return $redirect;
    }

    /**
     * Update an existing domain (PHP version, document root / pointing location, SSL).
     */
    public function update(Request $request, Domain $domain)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'php_version' => ['nullable', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'public_path' => ['nullable', 'string', 'max:255'],
            'ssl_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $res = $this->domainService->updateDomain($domain, $validated, $user);
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to update domain: ' . $e->getMessage());
        }

        $redirect = back()->with('success', "Domain '{$domain->domain}' updated successfully.");
        if (!empty($res['warning'])) {
            $redirect->with('warning', $res['warning']);
        }

        return $redirect;
    }

    /**
     * Delete a domain.
     */
    public function destroy(Request $request, Domain $domain)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        // Call lc-cli vhost remove
        $this->lcCli->run('vhost', ['remove', $domain->domain, '--delete-files']);

        $domainName = $domain->domain;
        $domain->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'domain.delete',
            'command' => "lc-cli vhost remove {$domainName} --delete-files",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['domain' => $domainName],
        ]);

        return redirect()->route('domains.index')->with('success', "Domain '{$domainName}' removed.");
    }

    /**
     * Switch PHP version for a domain.
     */
    public function switchPhp(Request $request, Domain $domain)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'php_version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        $res = $this->lcCli->run('vhost', ['php-switch', $domain->domain, $validated['php_version']]);

        if (($res['status'] ?? '') === 'success') {
            $domain->update(['php_version' => $validated['php_version']]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'domain.php_switch',
                'command' => "lc-cli vhost php-switch {$domain->domain} {$validated['php_version']}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['domain' => $domain->domain, 'new_version' => $validated['php_version']],
            ]);

            return back()->with('success', "Domain '{$domain->domain}' switched to PHP {$validated['php_version']}.");
        }

        return back()->with('error', 'Failed to switch PHP version: ' . ($res['message'] ?? 'CLI Error'));
    }

    /**
     * Subdomains list and create view.
     */
    public function subdomains(Request $request): Response
    {
        $user = Auth::user();

        $query = Domain::where('type', 'subdomain')->with(['user:id,username', 'parentDomain:id,domain']);

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $subdomains = $query->latest()->paginate(20)->withQueryString();

        $baseDomains = Domain::where('type', '!=', 'subdomain');
        if (! $user->isAdmin()) {
            $baseDomains->where('user_id', $user->id);
        }

        return Inertia::render('Domains/Subdomains', [
            'subdomains' => $subdomains,
            'baseDomains' => $baseDomains->get(['id', 'domain', 'user_id']),
        ]);
    }

    /**
     * Store a new subdomain.
     */
    public function storeSubdomain(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'subdomain_prefix' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'parent_domain_id' => ['required', 'exists:domains,id'],
        ]);

        $parentDomain = Domain::with('user.package')->findOrFail($validated['parent_domain_id']);

        if (! $user->isAdmin() && $parentDomain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $subdomainFqdn = strtolower($validated['subdomain_prefix']) . '.' . $parentDomain->domain;

        if (Domain::where('domain', $subdomainFqdn)->exists()) {
            return back()->withErrors(['subdomain_prefix' => "Subdomain '{$subdomainFqdn}' already exists."]);
        }

        // Quota check
        if (! $user->isAdmin() && $parentDomain->user && $parentDomain->user->package) {
            $currentSubCount = Domain::where('user_id', $parentDomain->user_id)->where('type', 'subdomain')->count();
            if ($currentSubCount >= $parentDomain->user->package->max_subdomains) {
                return back()->withErrors(['subdomain_prefix' => "Subdomain limit reached ({$parentDomain->user->package->max_subdomains})."]);
            }
        }

        $docRoot = "/home/{$parentDomain->user->username}/{$subdomainFqdn}/public_html";

        $cliRes = $this->lcCli->run('vhost', [
            'add',
            $subdomainFqdn,
            $parentDomain->user->username,
            $parentDomain->php_version ?? '83',
            $docRoot,
        ]);

        if (($cliRes['status'] ?? '') !== 'success') {
            return back()->withErrors(['subdomain_prefix' => 'VirtualHost creation failed: ' . ($cliRes['message'] ?? 'CLI Error')]);
        }

        Domain::create([
            'user_id' => $parentDomain->user_id,
            'domain' => $subdomainFqdn,
            'type' => 'subdomain',
            'parent_domain_id' => $parentDomain->id,
            'document_root' => $docRoot,
            'php_version' => $parentDomain->php_version ?? '83',
            'ssl_enabled' => false,
            'status' => 'active',
        ]);

        return redirect()->route('subdomains.index')->with('success', "Subdomain '{$subdomainFqdn}' created successfully.");
    }
}

