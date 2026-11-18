<?php

namespace App\Http\Controllers\ServerOps;

use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    public function __construct(
        protected ModuleDriverInterface $moduleCli
    ) {}

    /**
     * Optional Modules Manager.
     */
    public function index(): Response
    {
        $modules = $this->moduleCli->listModules();
        $current = Cache::get('module_install_current');
        $rawStatus = $current ? Cache::get("module_install_{$current}") : null;
        $installingModule = in_array($rawStatus, ['pending', 'provisioning'], true) ? $current : null;

        return Inertia::render('ServerOps/Modules', [
            'modules' => $modules,
            'installingModule' => $installingModule,
        ]);
    }

    /**
     * Install an optional module on-demand.
     */
    public function install(Request $request, string $module, InstallModuleAction $installModuleAction)
    {
        $allowed = ['ftp', 'dns', 'mail', 'csf', 'modsecurity', 'imunifyav'];
        if (! in_array($module, $allowed)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        try {
            $installModuleAction->handle($module, Auth::user(), $request->ip());

            return redirect()->back()->with('success', "Module '$module' installation queued.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Get the current background module installation status.
     */
    public function installStatus()
    {
        $module = Cache::get('module_install_current');
        if (! $module) {
            return response()->json(['module' => null, 'status' => null]);
        }

        $rawStatus = Cache::get("module_install_{$module}");
        $status = match ($rawStatus) {
            'pending', 'provisioning' => 'installing',
            'active' => 'installed',
            'failed' => 'failed',
            default => null,
        };

        return response()->json([
            'module' => $module,
            'status' => $status,
            'error' => $status === 'failed' ? Cache::get("module_install_{$module}_error") : null,
        ]);
    }

    /**
     * Dismiss the module installation status bar permanently.
     */
    public function dismissInstallStatus()
    {
        $module = Cache::get('module_install_current');
        if ($module) {
            Cache::forget("module_install_{$module}");
            Cache::forget("module_install_{$module}_error");
        }
        Cache::forget('module_install_current');

        return response()->json(['ok' => true]);
    }

    /**
     * Remove / uninstall an optional module.
     */
    public function remove(Request $request, string $module, RemoveModuleAction $removeModuleAction)
    {
        $allowed = ['ftp', 'dns', 'mail', 'csf', 'modsecurity', 'imunifyav'];
        if (! in_array($module, $allowed)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        try {
            $removeModuleAction->handle($module, Auth::user(), $request->ip());

            return redirect()->back()->with('success', "Module '$module' removed successfully.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
