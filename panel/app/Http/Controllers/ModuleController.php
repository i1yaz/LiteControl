<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Optional Modules Manager.
     */
    public function index(): Response
    {
        $res = $this->lcCli->run('module', ['list']);
        $modules = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        return Inertia::render('Modules/Index', [
            'modules' => $modules,
        ]);
    }

    /**
     * Install an optional module on-demand.
     */
    public function install(Request $request, string $module)
    {
        $allowed = ['ftp', 'dns', 'mail', 'csf', 'modsecurity', 'imunifyav'];
        if (!in_array($module, $allowed)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        $res = $this->lcCli->run('module', ['install', $module]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? "Failed to install module $module.");
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'install_module',
            'command' => "lc-cli module install $module",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['module' => $module]),
        ]);

        return redirect()->back()->with('success', "Module '$module' installation completed successfully.");
    }

    /**
     * Remove / uninstall an optional module.
     */
    public function remove(Request $request, string $module)
    {
        $allowed = ['ftp', 'dns', 'mail', 'csf', 'modsecurity', 'imunifyav'];
        if (!in_array($module, $allowed)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        $res = $this->lcCli->run('module', ['remove', $module]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? "Failed to remove module $module.");
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'remove_module',
            'command' => "lc-cli module remove $module",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['module' => $module]),
        ]);

        return redirect()->back()->with('success', "Module '$module' removed successfully.");
    }
}

