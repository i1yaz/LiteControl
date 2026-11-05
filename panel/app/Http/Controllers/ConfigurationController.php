<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ConfigurationController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * General Panel & Server Settings.
     */
    public function settings(Request $request): Response
    {
        $statsRes = $this->lcCli->run('server', ['stats']);
        $stats = ($statsRes['status'] ?? '') === 'success' ? ($statsRes['data'] ?? []) : [];

        $settings = [
            'panel_name' => config('app.name', 'LiteControl'),
            'admin_email' => Auth::user()->email ?? 'admin@localhost',
            'server_ip' => $stats['server_ip'] ?? '127.0.0.1',
            'hostname' => $stats['hostname'] ?? 'panel.local',
            'timezone' => $stats['timezone'] ?? 'UTC',
            'ols_version' => $stats['ols_version'] ?? '1.8.2',
            'default_php' => '83',
            'allow_registration' => false,
            'force_ssl' => true,
        ];

        return Inertia::render('Configuration/Settings', [
            'settings' => $settings,
            'stats' => $stats,
        ]);
    }

    /**
     * Update General Settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'panel_name' => ['required', 'string', 'max:60'],
            'admin_email' => ['required', 'email'],
            'timezone' => ['required', 'string', 'max:50'],
            'default_php' => ['required', 'string'],
        ]);

        // Sync timezone via lc-cli
        $this->lcCli->run('server', ['timezone', 'set', $validated['timezone']]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_settings',
            'command' => 'configuration.settings',
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', 'Panel settings updated successfully.');
    }

    /**
     * OpenLiteSpeed Tuning Configuration.
     */
    public function tuning(Request $request): Response
    {
        $res = $this->lcCli->run('tuning', ['get']);
        $tuning = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        return Inertia::render('Configuration/LiteSpeedTuning', [
            'tuning' => $tuning,
        ]);
    }

    /**
     * Update OpenLiteSpeed Tuning parameters.
     */
    public function updateTuning(Request $request)
    {
        $validated = $request->validate([
            'maxConnections' => ['required', 'integer', 'min:10', 'max:100000'],
            'maxSSLConnections' => ['required', 'integer', 'min:10', 'max:100000'],
            'connTimeout' => ['required', 'integer', 'min:5', 'max:3600'],
            'maxKeepAliveReq' => ['required', 'integer', 'min:1', 'max:50000'],
            'keepAliveTimeout' => ['required', 'integer', 'min:1', 'max:60'],
            'maxReqBodySize' => ['required', 'string', 'regex:/^[0-9]+[KMGkmg]?$/'],
            'enableGzipCompress' => ['required', 'in:0,1'],
            'gzipCompressLevel' => ['required', 'integer', 'min:1', 'max:9'],
            'quicEnable' => ['required', 'in:0,1'],
        ]);

        $args = ['set'];
        foreach ($validated as $key => $val) {
            $args[] = "{$key}={$val}";
        }

        $res = $this->lcCli->run('tuning', $args);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to update tuning configuration.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_tuning',
            'command' => 'lc-cli tuning set',
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', 'LiteSpeed tuning parameters updated and applied successfully.');
    }

    /**
     * Global PHP Limits Configuration across versions.
     */
    public function phpLimit(Request $request): Response
    {
        $version = $request->input('version', '83');
        $iniRes = $this->lcCli->run('php', ['ini-get', $version]);
        $directives = $iniRes['data']['directives'] ?? [];

        $listRes = $this->lcCli->run('php', ['list']);
        $installedVersions = array_filter(
            ($listRes['status'] ?? '') === 'success' ? ($listRes['data'] ?? []) : [],
            fn($v) => !empty($v['installed'])
        );

        return Inertia::render('Configuration/PhpLimit', [
            'currentVersion' => $version,
            'installedVersions' => array_values($installedVersions),
            'limits' => [
                'memory_limit' => $directives['memory_limit'] ?? '256M',
                'max_execution_time' => $directives['max_execution_time'] ?? '300',
                'upload_max_filesize' => $directives['upload_max_filesize'] ?? '64M',
                'post_max_size' => $directives['post_max_size'] ?? '64M',
                'max_input_vars' => $directives['max_input_vars'] ?? '3000',
            ],
        ]);
    }

    /**
     * Update PHP Limits for a specific version.
     */
    public function updatePhpLimit(Request $request)
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'in:74,80,81,82,83,84,85'],
            'memory_limit' => ['required', 'string', 'regex:/^[0-9]+[M|G]$/i'],
            'max_execution_time' => ['required', 'integer', 'min:10', 'max:86400'],
            'upload_max_filesize' => ['required', 'string', 'regex:/^[0-9]+[M|G]$/i'],
            'post_max_size' => ['required', 'string', 'regex:/^[0-9]+[M|G]$/i'],
            'max_input_vars' => ['required', 'integer', 'min:100', 'max:50000'],
        ]);

        $version = $validated['version'];
        unset($validated['version']);

        foreach ($validated as $key => $val) {
            $this->lcCli->run('php', ['ini-set', $version, "{$key}={$val}"]);
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_php_limits',
            'command' => "lc-cli php ini-set $version",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "PHP {$version} resource limits updated successfully.");
    }

    /**
     * View Server & Panel Error/Access Logs.
     */
    public function errorLogs(Request $request): Response
    {
        $type = $request->input('type', 'ols-error');
        $lines = (int) $request->input('lines', 100);

        if (!in_array($type, ['ols-error', 'ols-access', 'panel-error'])) {
            $type = 'ols-error';
        }

        $res = $this->lcCli->run('server', ['logs', $type, (string)$lines]);
        $data = ($res['status'] ?? '') === 'success' ? ($res['data'] ?? []) : [];

        return Inertia::render('Configuration/ErrorLogs', [
            'logType' => $type,
            'lines' => $lines,
            'logFile' => $data['file'] ?? '',
            'content' => $data['content'] ?? '',
        ]);
    }
}

