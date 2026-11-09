<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\LcCliService;
use App\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PhpController extends Controller
{
    protected LcCliService $lcCli;
    protected DomainService $domainService;

    public function __construct(LcCliService $lcCli, DomainService $domainService)
    {
        $this->lcCli = $lcCli;
        $this->domainService = $domainService;
    }

    /**
     * MultiPHP INI Editor.
     */
    public function ini(Request $request): Response
    {
        $version = $request->input('version', '83');

        $iniRes = $this->lcCli->run('php', ['ini-get', $version]);
        $iniData = ($iniRes['status'] ?? '') === 'success' ? ($iniRes['data'] ?? []) : [];

        $versionsRes = $this->lcCli->run('php', ['list']);
        $installedVersions = array_filter(
            ($versionsRes['status'] ?? '') === 'success' ? ($versionsRes['data'] ?? []) : [],
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Php/IniEditor', [
            'currentVersion' => $version,
            'iniData' => $iniData,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Update PHP INI directives.
     */
    public function updateIni(Request $request)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required to modify PHP INI configuration.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'directives' => ['required', 'array'],
            'directives.*' => ['nullable', 'string'],
        ]);

        $ver = $validated['version'];

        foreach ($validated['directives'] as $key => $val) {
            if ($val !== null) {
                $this->lcCli->run('php', ['ini-set', $ver, $key, (string) $val]);
            }
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'php.ini_update',
            'command' => "lc-cli php ini-set {$ver}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['version' => $ver, 'updated' => array_keys($validated['directives'])],
        ]);

        return back()->with('success', "PHP {$ver} configuration directives updated successfully.");
    }

    /**
     * MultiPHP Manager.
     */
    public function manager(Request $request): Response
    {
        $user = Auth::user();

        $domainsQuery = Domain::with('user:id,username');
        if (! $user->isAdmin()) {
            $domainsQuery->where('user_id', $user->id);
        }

        $domains = $domainsQuery->latest()->get();

        $versionsRes = $this->lcCli->run('php', ['list']);
        $installedVersions = array_filter(
            ($versionsRes['status'] ?? '') === 'success' ? ($versionsRes['data'] ?? []) : [],
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Php/MultiPhpManager', [
            'domains' => $domains,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Switch PHP version for multiple domains in batch.
     */
    public function batchSwitch(Request $request)
    {
        $validated = $request->validate([
            'domain_ids' => ['required', 'array'],
            'domain_ids.*' => ['exists:domains,id'],
            'php_version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        $user = Auth::user();
        $domains = Domain::whereIn('id', $validated['domain_ids']);

        if (! $user->isAdmin()) {
            $domains->where('user_id', $user->id);
        }

        $count = $this->domainService->batchSwitchPhpVersion(
            $domains->get(),
            $validated['php_version'],
            $user
        );

        return back()->with('success', "Updated PHP version to {$validated['php_version']} for {$count} domain(s).");
    }

    /**
     * PHP Extensions.
     */
    public function extensions(Request $request): Response
    {
        $version = $request->input('version', '83');

        $extRes = $this->lcCli->run('php', ['ext-list', $version]);
        $extensionsData = ($extRes['status'] ?? '') === 'success' ? ($extRes['data'] ?? []) : [
            'installed' => [],
            'available' => [],
        ];

        $versionsRes = $this->lcCli->run('php', ['list']);
        $installedVersions = array_filter(
            ($versionsRes['status'] ?? '') === 'success' ? ($versionsRes['data'] ?? []) : [],
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Php/Extensions', [
            'currentVersion' => $version,
            'extensionsData' => $extensionsData,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Toggle PHP extension (enable/disable).
     */
    public function toggleExtension(Request $request)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'extension' => ['required', 'string', 'alpha_dash'],
            'action' => ['required', 'in:enable,disable'],
        ]);

        $sub = $validated['action'] === 'enable' ? 'ext-enable' : 'ext-disable';
        $res = $this->lcCli->run('php', [$sub, $validated['version'], $validated['extension']]);

        if (($res['status'] ?? '') === 'success') {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => "php.extension_{$validated['action']}",
                'command' => "lc-cli php {$sub} {$validated['version']} {$validated['extension']}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => $validated,
            ]);

            return back()->with('success', "Extension '{$validated['extension']}' for PHP {$validated['version']} has been {$validated['action']}d.");
        }

        return back()->with('error', 'Extension operation failed: ' . ($res['message'] ?? 'Error'));
    }

    /**
     * PHP Versions management (lsphp74 - lsphp85).
     */
    public function versions(): Response
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $versionsRes = $this->lcCli->run('php', ['list']);
        $allVersions = ($versionsRes['status'] ?? '') === 'success' ? ($versionsRes['data'] ?? []) : [];

        return Inertia::render('Php/Versions', [
            'phpVersions' => $allVersions,
        ]);
    }

    /**
     * Install a PHP version.
     */
    public function installVersion(Request $request)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        $res = $this->lcCli->run('php', ['install', $validated['version']]);

        if (($res['status'] ?? '') === 'success') {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'php.install',
                'command' => "lc-cli php install {$validated['version']}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['version' => $validated['version']],
            ]);

            return back()->with('success', "PHP {$validated['version']} installed successfully.");
        }

        return back()->with('error', 'Installation failed: ' . ($res['message'] ?? 'CLI Error'));
    }

    /**
     * Remove a PHP version.
     */
    public function removeVersion(Request $request)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        if ($validated['version'] === '83') {
            return back()->with('error', 'Cannot remove primary panel PHP version (lsphp83).');
        }

        $res = $this->lcCli->run('php', ['remove', $validated['version']]);

        if (($res['status'] ?? '') === 'success') {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'php.remove',
                'command' => "lc-cli php remove {$validated['version']}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['version' => $validated['version']],
            ]);

            return back()->with('success', "PHP {$validated['version']} removed successfully.");
        }

        return back()->with('error', 'Removal failed: ' . ($res['message'] ?? 'CLI Error'));
    }

    /**
     * View loaded PHP modules (`php -m`).
     */
    public function modules(Request $request): Response
    {
        $version = $request->input('version', '83');

        $modRes = $this->lcCli->run('php', ['modules', $version]);
        $modules = ($modRes['status'] ?? '') === 'success' ? ($modRes['data'] ?? []) : [];

        $versionsRes = $this->lcCli->run('php', ['list']);
        $installedVersions = array_filter(
            ($versionsRes['status'] ?? '') === 'success' ? ($versionsRes['data'] ?? []) : [],
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Php/Modules', [
            'currentVersion' => $version,
            'modules' => $modules,
            'installedVersions' => array_values($installedVersions),
        ]);
    }
}

