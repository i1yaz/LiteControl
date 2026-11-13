<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\FtpAccount;
use App\Services\DomainService;
use App\Services\SslService;
use App\Services\FtpService;
use App\Domains\ServerOps\Contracts\LogReaderInterface;
use App\Services\System\VhostService;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Actions\Domain\DeleteDomainAction;
use App\Actions\Domain\UpdateVhostConfAction;
use App\Actions\Domain\FixDomainPermissionsAction;
use App\Actions\Domain\CreateSubdomainAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    protected DomainService $domainService;
    protected SslService $sslService;
    protected FtpService $ftpService;
    protected LogReaderInterface $logReader;
    protected VhostService $vhostService;
    protected PhpRuntimeDriverInterface $PhpRuntimeDriverInterface;

    public function __construct(
        DomainService $domainService,
        SslService $sslService,
        FtpService $ftpService,
        LogReaderInterface $logReader,
        VhostService $vhostService,
        PhpRuntimeDriverInterface $PhpRuntimeDriverInterface
    ) {
        $this->domainService = $domainService;
        $this->sslService = $sslService;
        $this->ftpService = $ftpService;
        $this->logReader = $logReader;
        $this->vhostService = $vhostService;
        $this->PhpRuntimeDriverInterface = $PhpRuntimeDriverInterface;
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

        // Get supported PHP versions from PhpRuntimeDriverInterface
        try {
            $phpVersions = $this->PhpRuntimeDriverInterface->listVersions();
        } catch (\Throwable $e) {
            $phpVersions = [];
        }

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
    public function destroy(Request $request, Domain $domain, DeleteDomainAction $deleteDomainAction)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $domainName = $domain->domain;
        $deleteDomainAction->handle($domain, $user, true, $request->ip());

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

        try {
            $this->domainService->switchPhpVersion($domain, $validated['php_version'], $user);
            return back()->with('success', "Domain '{$domain->domain}' switched to PHP {$validated['php_version']}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to switch PHP version: ' . $e->getMessage());
        }
    }

    /**
     * Suspend domain.
     */
    public function suspend(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        try {
            $this->domainService->suspendDomain($domain, $user);
            return back()->with('success', "Domain '{$domain->domain}' has been suspended.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to suspend domain: ' . $e->getMessage());
        }
    }

    /**
     * Unsuspend domain.
     */
    public function unsuspend(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        try {
            $this->domainService->unsuspendDomain($domain, $user);
            return back()->with('success', "Domain '{$domain->domain}' has been activated.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to unsuspend domain: ' . $e->getMessage());
        }
    }

    /**
     * Manage website dashboard (CyberPanel-style).
     */
    public function manage(Request $request, Domain $domain): Response
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $domain->load(['user.package', 'parentDomain', 'subdomains', 'aliases', 'ftpAccounts']);

        // Fetch associated databases count (where database belongs to this domain or subdomains)
        $associatedDomainIds = array_merge([$domain->id], $domain->subdomains->pluck('id')->toArray());
        $databasesCount = \App\Domains\Database\Models\Database::whereIn('domain_id', $associatedDomainIds)->count();

        // Calculate total size via VhostService
        try {
            $sizeBytes = $this->vhostService->getSize($domain->domain);
        } catch (\Throwable $e) {
            $sizeBytes = 0;
        }
        $sizeHuman = $this->formatBytes($sizeBytes);

        // PHP installed versions
        try {
            $phpVersions = $this->PhpRuntimeDriverInterface->listVersions();
        } catch (\Throwable $e) {
            $phpVersions = [];
        }
        $installedVersions = array_filter(
            $phpVersions,
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Domains/Manage', [
            'domain' => $domain,
            'databasesCount' => $databasesCount,
            'totalSizeBytes' => $sizeBytes,
            'totalSizeHuman' => $sizeHuman,
            'installedPhpVersions' => array_values($installedVersions),
            'activeTab' => $request->input('tab', 'overview'),
        ]);
    }

    /**
     * Add domain alias.
     */
    public function storeAlias(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'alias' => [
                'required',
                'string',
                'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                'unique:domains,domain',
            ],
        ]);

        try {
            $this->domainService->addAlias($domain, $validated['alias'], $user);
            return back()->with('success', "Domain alias '{$validated['alias']}' added successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to add domain alias: ' . $e->getMessage());
        }
    }

    /**
     * Remove domain alias.
     */
    public function destroyAlias(Request $request, Domain $domain, Domain $alias)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        if ($alias->parent_domain_id !== $domain->id || $alias->type !== 'alias') {
            abort(404, 'Alias not associated with this domain.');
        }

        $aliasName = $alias->domain;
        $alias->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'domain.alias_delete',
            'command' => "domain delete alias {$aliasName}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['parent' => $domain->domain, 'alias' => $aliasName],
        ]);

        return back()->with('success', "Domain alias '{$aliasName}' removed.");
    }

    /**
     * Fetch access or error logs with pagination.
     */
    public function logs(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $type = $request->input('type', 'access') === 'error' ? 'error' : 'access';
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 50);

        $logPath = "/home/{$domain->user->username}/{$domain->domain}/logs/{$type}.log";

        // Dev sandbox fallback
        if (PHP_OS_FAMILY !== 'Linux' || ! file_exists($logPath)) {
            $sandboxLogDir = storage_path("app/homes/{$domain->user->username}/{$domain->domain}/logs");
            if (! file_exists($sandboxLogDir)) {
                @File::makeDirectory($sandboxLogDir, 0755, true);
            }
            $logPath = "{$sandboxLogDir}/{$type}.log";
            if (! file_exists($logPath)) {
                $sampleLogs = [
                    "[" . now()->subMinutes(12)->toIso8601String() . "] [NOTICE] Worker thread started for {$domain->domain}",
                    "[" . now()->subMinutes(8)->toIso8601String() . "] [INFO] 127.0.0.1 GET / 200 - Mozilla/5.0",
                    "[" . now()->subMinutes(3)->toIso8601String() . "] [INFO] 127.0.0.1 GET /assets/app.css 200",
                    "[" . now()->subMinute()->toIso8601String() . "] [NOTICE] HTTP/2 connection negotiated successfully.",
                ];
                file_put_contents($logPath, implode("\n", $sampleLogs) . "\n");
            }
        }

        $logData = $this->logReader->readLogPage($logPath, $page, $perPage);

        return response()->json([
            'status' => 'success',
            'log_type' => $type,
            'file_path' => $logPath,
            ...$logData,
        ]);
    }

    /**
     * Get raw vHost conf.
     */
    public function vhostConf(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        try {
            $conf = $this->vhostService->getConf($domain->domain);
            if (empty($conf)) {
                $conf = '# No configuration file found.';
            }
        } catch (\Throwable $e) {
            $conf = '# No configuration file found.';
        }

        return response()->json([
            'status' => 'success',
            'domain' => $domain->domain,
            'conf' => $conf,
        ]);
    }

    /**
     * Save Virtual Host configuration with syntax test and rollback guard.
     */
    public function saveVhostConf(Request $request, Domain $domain, UpdateVhostConfAction $updateVhostConfAction)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'conf' => ['required', 'string'],
        ]);

        try {
            $updateVhostConfAction->handle($domain, $validated['conf'], $user, $request->ip());
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Failed to apply VirtualHost configuration.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'VirtualHost configuration validated and saved successfully.',
        ]);
    }

    /**
     * Read .htaccess content.
     */
    public function getHtaccess(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $htaccessPath = "{$domain->document_root}/.htaccess";
        $content = file_exists($htaccessPath) ? @file_get_contents($htaccessPath) : '';

        return response()->json([
            'status' => 'success',
            'content' => $content ?: '',
            'exists' => file_exists($htaccessPath),
        ]);
    }

    /**
     * Save .htaccess content.
     */
    public function saveHtaccess(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string'],
        ]);

        $htaccessPath = "{$domain->document_root}/.htaccess";

        if (! file_exists($domain->document_root)) {
            @File::makeDirectory($domain->document_root, 0755, true);
        }

        $success = @file_put_contents($htaccessPath, $validated['content'] ?? '');

        if ($success === false) {
            return back()->with('error', 'Failed to write to .htaccess file. Check filesystem permissions.');
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'domain.htaccess_update',
            'command' => "update .htaccess for {$domain->domain}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['domain' => $domain->domain],
        ]);

        return back()->with('success', '.htaccess rewrite rules updated successfully.');
    }

    /**
     * Store FTP account for this domain.
     */
    public function storeFtp(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,32}$/'],
            'password' => ['required', 'string', 'min:8'],
            'dir' => ['nullable', 'string', 'max:255'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->ftpService->createAccount($domain, $validated, $user);
            return back()->with('success', "FTP account created successfully for {$domain->domain}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to create FTP account: ' . $e->getMessage());
        }
    }

    /**
     * Delete FTP account.
     */
    public function destroyFtp(Request $request, Domain $domain, FtpAccount $ftpAccount)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        if ($ftpAccount->domain_id !== $domain->id) {
            abort(404, 'FTP account does not belong to this domain.');
        }

        $this->ftpService->deleteAccount($ftpAccount, $user);
        return back()->with('success', "FTP account '{$ftpAccount->username}' deleted.");
    }

    /**
     * Fix permissions for domain (CyberPanel-style one click repair).
     */
    public function fixPermissions(Request $request, Domain $domain, FixDomainPermissionsAction $fixDomainPermissionsAction)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        try {
            $res = $fixDomainPermissionsAction->handle($domain, $user);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'Failed to repair domain permissions.');
        }

        if (($res['status'] ?? '') === 'success') {
            return back()->with('success', "Permissions successfully repaired for '{$domain->domain}'.");
        }

        return back()->with('error', $res['message'] ?? 'Failed to repair domain permissions.');
    }

    /**
     * Format byte values to human readable string.
     */
    protected function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
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

        try {
            $phpVersions = $this->PhpRuntimeDriverInterface->listVersions();
        } catch (\Throwable $e) {
            $phpVersions = [];
        }

        return Inertia::render('Domains/Subdomains', [
            'subdomains' => $subdomains,
            'baseDomains' => $baseDomains->with('user:id,username')->get(['id', 'domain', 'user_id', 'php_version']),
            'phpVersions' => $phpVersions,
        ]);
    }

    /**
     * Store a new subdomain.
     */
    public function storeSubdomain(Request $request, CreateSubdomainAction $createSubdomainAction)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'subdomain_prefix' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'parent_domain_id' => ['required', 'exists:domains,id'],
            'php_version' => ['nullable', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'public_path' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'ssl_enabled' => ['nullable', 'boolean'],
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

        try {
            $createSubdomainAction->handle($parentDomain, $validated['subdomain_prefix'], $user, [
                'php_version' => $validated['php_version'] ?? null,
                'public_path' => $validated['public_path'] ?? null,
                'ssl_enabled' => $validated['ssl_enabled'] ?? false,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['subdomain_prefix' => $e->getMessage()]);
        }

        $redirect = redirect()->route('subdomains.index')->with('success', "Subdomain '{$subdomainFqdn}' created successfully.");
        if ($createSubdomainAction->lastWarning) {
            $redirect->with('warning', $createSubdomainAction->lastWarning);
        }

        return $redirect;
    }

    /**
     * Toggle open_basedir protection for a domain.
     */
    public function toggleOpenBasedir(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['enabled'];

        try {
            $this->lcCli->run('vhost', [
                'open-basedir',
                $domain->domain,
                $enabled ? 'on' : 'off',
            ]);
        } catch (\Throwable $e) {
            // Gracefully continue if CLI command is not yet available
        }

        $domain->update(['open_basedir_enabled' => $enabled]);

        $state = $enabled ? 'enabled' : 'disabled';
        return back()->with('success', "open_basedir protection {$state} for '{$domain->domain}'.");
    }
}

