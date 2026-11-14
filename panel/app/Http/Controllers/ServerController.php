<?php

namespace App\Http\Controllers;

use App\Domains\ServerOps\Actions\KillProcessAction;
use App\Domains\ServerOps\Actions\ManageServiceAction;
use App\Domains\ServerOps\Actions\ToggleWebAdminAction;
use App\Domains\ServerOps\Actions\UpdateHostnameAction;
use App\Domains\ServerOps\Actions\UpdateTimezoneAction;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function __construct(
        protected ServerDriverInterface $serverCli
    ) {}

    /**
     * Service Manager.
     */
    public function services(): Response
    {
        $services = $this->serverCli->getServices();
        $webAdmin = $this->serverCli->getWebAdminStatus();

        return Inertia::render('Server/Services', [
            'services' => $services,
            'webAdmin' => $webAdmin,
        ]);
    }

    /**
     * Enable or update OpenLiteSpeed WebAdmin console settings.
     */
    public function enableWebAdmin(Request $request, ToggleWebAdminAction $toggleWebAdminAction)
    {
        $validated = $request->validate([
            'allowed_ips' => 'nullable|string|max:500',
        ]);

        try {
            $toggleWebAdminAction->enable($validated['allowed_ips'] ?? null, $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'OpenLiteSpeed WebAdmin console enabled successfully.');
    }

    /**
     * Disable OpenLiteSpeed WebAdmin console.
     */
    public function disableWebAdmin(Request $request, ToggleWebAdminAction $toggleWebAdminAction)
    {
        try {
            $toggleWebAdminAction->disable($request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'OpenLiteSpeed WebAdmin console disabled successfully.');
    }

    /**
     * Execute an action on a system service.
     */
    public function serviceAction(Request $request, string $service, string $action, ManageServiceAction $manageServiceAction)
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'reload'])) {
            return redirect()->back()->with('error', "Invalid action '{$action}'.");
        }

        try {
            $manageServiceAction->handle($service, $action, $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Service '{$service}' successfully {$action}ed.");
    }

    /**
     * Process Manager.
     */
    public function processes(): Response
    {
        $processes = $this->serverCli->getProcesses();

        return Inertia::render('Server/ProcessManager', [
            'processes' => $processes,
        ]);
    }

    /**
     * Terminate a running process.
     */
    public function killProcess(Request $request, int $pid, KillProcessAction $killProcessAction)
    {
        if ($pid <= 1) {
            return redirect()->back()->with('error', 'Cannot terminate system init PID.');
        }

        try {
            $killProcessAction->handle($pid, $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Process {$pid} terminated successfully.");
    }

    /**
     * Time Zone Settings.
     */
    public function timezone(): Response
    {
        $currentTz = $this->serverCli->getTimezone();

        $popularZones = [
            'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Amsterdam', 'Europe/Rome',
            'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Shanghai',
            'Australia/Sydney', 'Pacific/Auckland',
        ];

        return Inertia::render('Server/TimeZone', [
            'currentTimezone' => $currentTz,
            'popularZones' => $popularZones,
        ]);
    }

    /**
     * Update Time Zone.
     */
    public function updateTimezone(Request $request, UpdateTimezoneAction $updateTimezoneAction)
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', 'regex:/^[A-Za-z0-9_+-]+(\/[A-Za-z0-9_+-]+)*$/'],
        ]);

        try {
            $updateTimezoneAction->handle($validated['timezone'], $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Timezone updated to {$validated['timezone']}.");
    }

    /**
     * Change Hostname View.
     */
    public function hostname(): Response
    {
        $currentHostname = $this->serverCli->getHostname();

        return Inertia::render('Server/ChangeHostname', [
            'currentHostname' => $currentHostname,
        ]);
    }

    /**
     * Update Server Hostname.
     */
    public function updateHostname(Request $request, UpdateHostnameAction $updateHostnameAction)
    {
        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/'],
        ]);

        try {
            $updateHostnameAction->handle($validated['hostname'], $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Hostname successfully updated to '{$validated['hostname']}'.");
    }

    /**
     * Restart Server / Web Server View.
     */
    public function restart(): Response
    {
        $stats = $this->serverCli->getStats();

        return Inertia::render('Server/Restart', [
            'stats' => $stats,
        ]);
    }

    /**
     * Execute Server / Service Restart.
     */
    public function executeRestart(Request $request, ManageServiceAction $manageServiceAction)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:ols_restart,ols_reload,mariadb_restart,redis_restart'],
        ]);

        $actionMap = [
            'ols_restart' => ['service' => 'openlitespeed', 'act' => 'restart'],
            'ols_reload' => ['service' => 'openlitespeed', 'act' => 'reload'],
            'mariadb_restart' => ['service' => 'mariadb', 'act' => 'restart'],
            'redis_restart' => ['service' => 'redis-server', 'act' => 'restart'],
        ];

        $target = $actionMap[$validated['action']];

        try {
            $manageServiceAction->handle($target['service'], $target['act'], $request->user(), $request->ip());
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Successfully executed {$target['act']} on {$target['service']}.");
    }

    /**
     * Detailed System Statistics.
     */
    public function statistics(): Response
    {
        $stats = $this->serverCli->getStats();

        return Inertia::render('Server/SystemStatistics', [
            'stats' => $stats,
        ]);
    }

    /**
     * Fast check of OLS daemon status.
     * Returns JSON with status: 'online' | 'offline'.
     */
    public function olsStatus()
    {
        // 1. If running in mock mode on dev/Windows environment
        if ($this->serverCli->isMockMode()) {
            return response()->json([
                'status' => 'online',
                'service' => 'openlitespeed',
                'mock' => true,
            ]);
        }

        // 2. Query lc-cli service status directly (single source of truth)
        $services = $this->serverCli->getServices();
        foreach ($services as $service) {
            $name = $service['name'] ?? $service['service'] ?? '';
            if (in_array($name, ['openlitespeed', 'lshttpd', 'lsws'], true)) {
                $isActive = ($service['status'] ?? '') === 'active';

                return response()->json([
                    'status' => $isActive ? 'online' : 'offline',
                    'service' => 'openlitespeed',
                ]);
            }
        }

        return response()->json([
            'status' => 'offline',
            'service' => 'openlitespeed',
        ]);
    }
}
