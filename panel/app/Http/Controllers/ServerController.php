<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Service Manager.
     */
    public function services(): Response
    {
        $res = $this->lcCli->run('server', ['services']);
        $services = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        $webAdminRes = $this->lcCli->run('server', ['webadmin-status']);
        $webAdmin = ($webAdminRes['status'] ?? '') === 'success' ? ($webAdminRes['data'] ?? []) : [
            'enabled' => false,
            'port' => 7080,
            'allowed_ips' => '',
        ];

        return Inertia::render('Server/Services', [
            'services' => $services,
            'webAdmin' => $webAdmin,
        ]);
    }

    /**
     * Enable or update OpenLiteSpeed WebAdmin console settings.
     */
    public function enableWebAdmin(Request $request)
    {
        $validated = $request->validate([
            'allowed_ips' => 'nullable|string|max:500',
        ]);

        $ips = trim($validated['allowed_ips'] ?? '');
        $res = $this->lcCli->run('server', array_filter(['webadmin-enable', $ips]));

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to enable WebAdmin console.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'webadmin_enabled',
            'command' => "lc-cli server webadmin-enable" . ($ips ? " {$ips}" : ''),
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['allowed_ips' => $ips]),
        ]);

        return redirect()->back()->with('success', 'OpenLiteSpeed WebAdmin console enabled successfully.');
    }

    /**
     * Disable OpenLiteSpeed WebAdmin console.
     */
    public function disableWebAdmin(Request $request)
    {
        $res = $this->lcCli->run('server', ['webadmin-disable']);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to disable WebAdmin console.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'webadmin_disabled',
            'command' => 'lc-cli server webadmin-disable',
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode([]),
        ]);

        return redirect()->back()->with('success', 'OpenLiteSpeed WebAdmin console disabled successfully.');
    }

    /**
     * Execute an action on a system service.
     */
    public function serviceAction(Request $request, string $service, string $action)
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'reload'])) {
            return redirect()->back()->with('error', "Invalid action '{$action}'.");
        }

        $res = $this->lcCli->run('server', ['service-action', $service, $action]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? "Failed to {$action} service {$service}.");
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => "service_{$action}",
            'command' => "lc-cli server service-action {$service} {$action}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['service' => $service, 'action' => $action]),
        ]);

        return redirect()->back()->with('success', "Service '{$service}' successfully {$action}ed.");
    }

    /**
     * Process Manager.
     */
    public function processes(): Response
    {
        $res = $this->lcCli->run('server', ['processes']);
        $processes = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        return Inertia::render('Server/ProcessManager', [
            'processes' => $processes,
        ]);
    }

    /**
     * Terminate a running process.
     */
    public function killProcess(Request $request, int $pid)
    {
        if ($pid <= 1) {
            return redirect()->back()->with('error', 'Cannot terminate system init PID.');
        }

        $res = $this->lcCli->run('server', ['kill-process', (string)$pid]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? "Failed to kill process {$pid}.");
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'kill_process',
            'command' => "lc-cli server kill-process {$pid}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['pid' => $pid]),
        ]);

        return redirect()->back()->with('success', "Process {$pid} terminated successfully.");
    }

    /**
     * Time Zone Settings.
     */
    public function timezone(): Response
    {
        $res = $this->lcCli->run('server', ['timezone', 'get']);
        $currentTz = $res['data']['timezone'] ?? 'UTC';

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
    public function updateTimezone(Request $request)
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', 'regex:/^[A-Za-z0-9_+-]+(\/[A-Za-z0-9_+-]+)*$/'],
        ]);

        $res = $this->lcCli->run('server', ['timezone', 'set', $validated['timezone']]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to update timezone.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_timezone',
            'command' => "lc-cli server timezone set {$validated['timezone']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "Timezone updated to {$validated['timezone']}.");
    }

    /**
     * Change Hostname View.
     */
    public function hostname(): Response
    {
        $res = $this->lcCli->run('server', ['hostname', 'get']);
        $currentHostname = $res['data']['hostname'] ?? 'panel.local';

        return Inertia::render('Server/ChangeHostname', [
            'currentHostname' => $currentHostname,
        ]);
    }

    /**
     * Update Server Hostname.
     */
    public function updateHostname(Request $request)
    {
        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/'],
        ]);

        $res = $this->lcCli->run('server', ['hostname', 'set', $validated['hostname']]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to update hostname.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_hostname',
            'command' => "lc-cli server hostname set {$validated['hostname']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "Hostname successfully updated to '{$validated['hostname']}'.");
    }

    /**
     * Restart Server / Web Server View.
     */
    public function restart(): Response
    {
        $res = $this->lcCli->run('server', ['stats']);
        $stats = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        return Inertia::render('Server/Restart', [
            'stats' => $stats,
        ]);
    }

    /**
     * Execute Server / Service Restart.
     */
    public function executeRestart(Request $request)
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
        $res = $this->lcCli->run('server', ['service-action', $target['service'], $target['act']]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? "Restart failed for {$target['service']}.");
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'server_restart_action',
            'command' => "lc-cli server service-action {$target['service']} {$target['act']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "Successfully executed {$target['act']} on {$target['service']}.");
    }

    /**
     * Detailed System Statistics.
     */
    public function statistics(): Response
    {
        $res = $this->lcCli->run('server', ['stats']);
        $stats = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

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
        // 1. If running on Windows / dev machine without Linux systemctl or OLS binaries, fallback to mock mode
        if (PHP_OS_FAMILY === 'Windows') {
            return response()->json([
                'status' => 'online',
                'service' => 'openlitespeed',
                'mock' => true,
            ]);
        }

        // 2. Real Linux check
        $olsCtrl = '/usr/local/lsws/bin/lswsctrl';
        $isOnline = false;

        if (file_exists($olsCtrl)) {
            $output = shell_exec("{$olsCtrl} status 2>&1");
            // lswsctrl status returns "[ERROR] litespeed is not running." when stopped
            // and contains "litespeed is running with PID" or "running" when active
            $isOnline = (is_string($output) && stripos($output, 'running') !== false && stripos($output, 'not running') === false);
        } else {
            $output = shell_exec('systemctl is-active lshttpd 2>/dev/null || systemctl is-active lsws 2>/dev/null || systemctl is-active openlitespeed 2>/dev/null');
            $isOnline = (is_string($output) && trim($output) === 'active');
        }

        return response()->json([
            'status' => $isOnline ? 'online' : 'offline',
            'service' => 'openlitespeed',
        ]);
    }
}

