<?php

namespace App\Http\Controllers;

use App\Domains\PanelConfig\Actions\UpdatePhpLimitsAction;
use App\Domains\PanelConfig\Actions\UpdateSettingsAction;
use App\Domains\PanelConfig\Actions\UpdateTuningAction;
use App\Domains\PanelConfig\Contracts\TuningDriverInterface;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ConfigurationController extends Controller
{
    public function __construct(
        protected ServerDriverInterface $serverCli,
        protected TuningDriverInterface $tuningCli,
        protected PhpRuntimeDriverInterface $phpCli
    ) {}

    /**
     * General Panel & Server Settings.
     */
    public function settings(Request $request): Response
    {
        $stats = $this->serverCli->getStats();

        $settings = [
            'panel_name' => config('app.name', 'LiteControl'),
            'admin_email' => Auth::user()->email ?? 'admin@localhost',
            'server_ip' => $stats['server_ip'] ?? '127.0.0.1',
            'hostname' => $stats['hostname'] ?? 'panel.local',
            'timezone' => $stats['timezone'] ?? 'UTC',
            'ols_version' => $stats['ols_version'] ?? '1.8.2',
            'default_php' => '83',
            'default_theme' => config('themes.default', 'avionics'),
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
    public function updateSettings(Request $request, UpdateSettingsAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'panel_name' => ['required', 'string', 'max:60'],
            'admin_email' => ['required', 'email'],
            'timezone' => ['required', 'string', 'max:50'],
            'default_php' => ['required', 'string'],
            'default_theme' => ['nullable', 'string'],
        ]);

        try {
            $action->handle($validated, $request->user(), $request->ip());

            return redirect()->back()->with('success', 'Panel settings updated successfully.');
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * OpenLiteSpeed Tuning Configuration.
     */
    public function tuning(Request $request): Response
    {
        $tuning = $this->tuningCli->getTuning();

        return Inertia::render('Configuration/LiteSpeedTuning', [
            'tuning' => $tuning,
        ]);
    }

    /**
     * Update OpenLiteSpeed Tuning parameters.
     */
    public function updateTuning(Request $request, UpdateTuningAction $action): RedirectResponse
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

        try {
            $action->handle($validated, $request->user(), $request->ip());

            return redirect()->back()->with('success', 'LiteSpeed tuning parameters updated and applied successfully.');
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Global PHP Limits Configuration across versions.
     */
    public function phpLimit(Request $request): Response
    {
        $version = $request->input('version', '83');

        $directives = [];
        try {
            $iniRes = $this->phpCli->getIni($version);
            $directives = $iniRes['directives'] ?? [];
        } catch (\Throwable) {
            // Fallback for missing/invalid PHP INI
        }

        $installedVersions = [];
        try {
            $listRes = $this->phpCli->listVersions();
            $installedVersions = array_filter(
                $listRes,
                fn($v) => !empty($v['installed'])
            );
        } catch (\Throwable) {
            // Fallback for list failure
        }

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
    public function updatePhpLimit(Request $request, UpdatePhpLimitsAction $action): RedirectResponse
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

        try {
            $action->handle($version, $validated, $request->user(), $request->ip());

            return redirect()->back()->with('success', "PHP {$version} resource limits updated successfully.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
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

        $data = $this->serverCli->getLogs($type, $lines);

        return Inertia::render('Configuration/ErrorLogs', [
            'logType' => $type,
            'lines' => $lines,
            'logFile' => $data['file'] ?? '',
            'content' => $data['content'] ?? '',
        ]);
    }
}
