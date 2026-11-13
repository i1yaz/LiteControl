<?php

namespace App\Http\Controllers;

use App\Domains\PhpRuntime\Actions\InstallPhpVersionAction;
use App\Domains\PhpRuntime\Actions\RemovePhpVersionAction;
use App\Domains\PhpRuntime\Actions\TogglePhpExtensionAction;
use App\Domains\PhpRuntime\Actions\UpdatePhpIniAction;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PhpController extends Controller
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpCli,
        protected DomainService $domainService
    ) {}

    /**
     * MultiPHP INI Editor.
     */
    public function ini(Request $request): Response
    {
        $version = $request->input('version', '83');

        try {
            $iniData = $this->phpCli->getIni(new PhpVersion($version));
        } catch (LcCliException $e) {
            $iniData = [];
        }

        try {
            $versions = $this->phpCli->listVersions();
            $installedVersions = array_filter(
                $versions,
                fn($v) => !empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        return Inertia::render('Php/IniEditor', [
            'currentVersion' => $version,
            'iniData' => $iniData,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Update PHP INI directives.
     */
    public function updateIni(Request $request, UpdatePhpIniAction $updatePhpIniAction)
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

        try {
            $updatePhpIniAction->handle(new PhpVersion($ver), $validated['directives'], Auth::user(), $request->ip());

            return back()->with('success', "PHP {$ver} configuration directives updated successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed updating PHP configuration: ' . $e->getMessage());
        }
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

        try {
            $versions = $this->phpCli->listVersions();
            $installedVersions = array_filter(
                $versions,
                fn($v) => !empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

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

        try {
            $extensionsData = $this->phpCli->listExtensions(new PhpVersion($version));
        } catch (LcCliException $e) {
            $extensionsData = [
                'installed' => [],
                'available' => [],
            ];
        }

        try {
            $versions = $this->phpCli->listVersions();
            $installedVersions = array_filter(
                $versions,
                fn($v) => !empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        return Inertia::render('Php/Extensions', [
            'currentVersion' => $version,
            'extensionsData' => $extensionsData,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Toggle PHP extension (enable/disable).
     */
    public function toggleExtension(Request $request, TogglePhpExtensionAction $togglePhpExtensionAction)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'extension' => ['required', 'string', 'alpha_dash'],
            'action' => ['required', 'in:enable,disable'],
        ]);

        try {
            $togglePhpExtensionAction->handle(
                new PhpVersion($validated['version']),
                new PhpExtensionName($validated['extension']),
                $validated['action'],
                Auth::user(),
                $request->ip()
            );

            return back()->with('success', "Extension '{$validated['extension']}' for PHP {$validated['version']} has been {$validated['action']}d.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Extension operation failed: ' . $e->getMessage());
        }
    }

    /**
     * PHP Versions management (lsphp74 - lsphp85).
     */
    public function versions(): Response
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        try {
            $allVersions = $this->phpCli->listVersions();
        } catch (LcCliException $e) {
            $allVersions = [];
        }

        return Inertia::render('Php/Versions', [
            'phpVersions' => $allVersions,
        ]);
    }

    /**
     * Install a PHP version.
     */
    public function installVersion(Request $request, InstallPhpVersionAction $installPhpVersionAction)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Administrator privileges required.');
        }

        $validated = $request->validate([
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        try {
            $installPhpVersionAction->handle(new PhpVersion($validated['version']), Auth::user(), $request->ip());

            return back()->with('success', "PHP {$validated['version']} installed successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Installation failed: ' . $e->getMessage());
        }
    }

    /**
     * Remove a PHP version.
     */
    public function removeVersion(Request $request, RemovePhpVersionAction $removePhpVersionAction)
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

        try {
            $removePhpVersionAction->handle(new PhpVersion($validated['version']), Auth::user(), $request->ip());

            return back()->with('success', "PHP {$validated['version']} removed successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Removal failed: ' . $e->getMessage());
        }
    }

    /**
     * View loaded PHP modules (`php -m`).
     */
    public function modules(Request $request): Response
    {
        $version = $request->input('version', '83');

        try {
            $modules = $this->phpCli->listLoadedModules(new PhpVersion($version));
        } catch (LcCliException $e) {
            $modules = [];
        }

        try {
            $versions = $this->phpCli->listVersions();
            $installedVersions = array_filter(
                $versions,
                fn($v) => !empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        return Inertia::render('Php/Modules', [
            'currentVersion' => $version,
            'modules' => $modules,
            'installedVersions' => array_values($installedVersions),
        ]);
    }
}
