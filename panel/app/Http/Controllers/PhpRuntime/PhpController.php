<?php

namespace App\Http\Controllers\PhpRuntime;

use App\Domains\PhpRuntime\Actions\InstallPhpExtensionAction;
use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Actions\InstallPhpVersionAction;
use App\Domains\PhpRuntime\Actions\RemovePhpVersionAction;
use App\Domains\PhpRuntime\Actions\UninstallPhpExtensionAction;
use App\Domains\PhpRuntime\Actions\UpdatePhpIniAction;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PhpRuntime\ManagePhpExtensionPackageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PhpController extends Controller
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpCli
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
                fn ($v) => ! empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        return Inertia::render('PhpRuntime/IniEditor', [
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
            'version' => ['required', 'string', Rule::in(PhpVersion::SUPPORTED)],
            'directives' => ['required', 'array'],
            'directives.*' => ['nullable', 'string'],
        ]);

        $ver = $validated['version'];

        try {
            $updatePhpIniAction->handle(new PhpVersion($ver), $validated['directives'], Auth::user(), $request->ip());

            return back()->with('success', "PHP {$ver} configuration directives updated successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed updating PHP configuration: '.$e->getMessage());
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
                fn ($v) => ! empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        return Inertia::render('PhpRuntime/MultiPhpManager', [
            'domains' => $domains,
            'installedVersions' => array_values($installedVersions),
        ]);
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
                fn ($v) => ! empty($v['installed'])
            );
        } catch (LcCliException $e) {
            $installedVersions = [];
        }

        try {
            $packages = $this->phpCli->listAvailablePackages(new PhpVersion($version));
        } catch (LcCliException $e) {
            $packages = [];
        }

        return Inertia::render('PhpRuntime/Extensions', [
            'currentVersion' => $version,
            'extensionsData' => $extensionsData,
            'packages' => $packages,
            'installedVersions' => array_values($installedVersions),
        ]);
    }

    /**
     * Install a PHP extension package via apt/dnf.
     */
    public function installExtension(ManagePhpExtensionPackageRequest $request, InstallPhpExtensionAction $action)
    {
        $validated = $request->validated();

        try {
            $action->handle($validated['version'], $validated['extension'], Auth::user());

            return back()->with('success', "Extension '{$validated['extension']}' for PHP {$validated['version']} installed.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Extension installation failed: '.$e->getMessage());
        }
    }

    /**
     * Uninstall a PHP extension package via apt/dnf.
     */
    public function uninstallExtension(ManagePhpExtensionPackageRequest $request, UninstallPhpExtensionAction $action)
    {
        $validated = $request->validated();

        try {
            $action->handle($validated['version'], $validated['extension'], Auth::user());

            return back()->with('success', "Extension '{$validated['extension']}' for PHP {$validated['version']} uninstalled.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Extension removal failed: '.$e->getMessage());
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

        $cliUsageCounts = User::query()
            ->whereNotNull('php_cli_version')
            ->selectRaw('php_cli_version, COUNT(*) as count')
            ->groupBy('php_cli_version')
            ->pluck('count', 'php_cli_version')
            ->toArray();

        return Inertia::render('PhpRuntime/Versions', [
            'phpVersions' => $allVersions,
            'cliUsageCounts' => $cliUsageCounts,
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

        $validator = Validator::make($request->all(), [
            'version' => ['required', 'string', Rule::in(PhpVersion::SUPPORTED)],
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first('version') ?: 'Invalid PHP version.');
        }

        $validated = $validator->validated();

        try {
            $installPhpVersionAction->handle(new PhpVersion($validated['version']), Auth::user(), $request->ip());

            return back()->with('success', "PHP {$validated['version']} installation queued.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Installation failed: '.$e->getMessage());
        }
    }

    /**
     * Get the current background PHP installation status.
     */
    public function installStatus()
    {
        $version = Cache::get('php_install_current_version');
        if (! $version) {
            return response()->json(['version' => null, 'status' => null]);
        }

        $rawStatus = Cache::get("php_install_{$version}");
        $status = match ($rawStatus) {
            'pending', 'provisioning' => 'installing',
            'active' => 'installed',
            'failed' => 'failed',
            default => null,
        };

        return response()->json([
            'version' => $version,
            'status' => $status,
        ]);
    }

    /**
     * Dismiss the PHP installation status bar permanently.
     */
    public function dismissInstallStatus()
    {
        $version = Cache::get('php_install_current_version');
        if ($version) {
            Cache::forget("php_install_{$version}");
        }
        Cache::forget('php_install_current_version');

        return response()->json(['ok' => true]);
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
            'version' => ['required', 'string', Rule::in(PhpVersion::SUPPORTED)],
        ]);

        if ($validated['version'] === '83') {
            return back()->with('error', 'Cannot remove primary panel PHP version (lsphp83).');
        }

        try {
            $removePhpVersionAction->handle(new PhpVersion($validated['version']), Auth::user(), $request->ip());

            return back()->with('success', "PHP {$validated['version']} removed successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Removal failed: '.$e->getMessage());
        }
    }
}
