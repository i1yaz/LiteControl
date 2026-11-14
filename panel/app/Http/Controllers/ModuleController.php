<?php

namespace App\Http\Controllers;

use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return Inertia::render('Modules/Index', [
            'modules' => $modules,
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

            return redirect()->back()->with('success', "Module '$module' installation completed successfully.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
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
