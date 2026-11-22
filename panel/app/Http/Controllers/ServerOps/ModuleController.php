<?php

namespace App\Http\Controllers\ServerOps;

use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\ServerOps\ModuleRegistry;
use App\Exceptions\System\LcCliException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    private const CLAMAV_MIN_RAM_MB = 4096;

    public function __construct(
        protected ModuleDriverInterface $moduleCli,
        protected ServerDriverInterface $serverDriver,
    ) {}

    /**
     * Optional Modules Manager.
     */
    public function index(): Response
    {
        $rawModules = ModuleRegistry::mergeWithCliList($this->moduleCli->listModules());
        $webmailHost = Cache::get('mail_webmail_hostname');
        if (! is_string($webmailHost) || $webmailHost === '') {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $webmailHost = is_string($appHost) && $appHost !== '' && $appHost !== 'localhost'
                ? "webmail.{$appHost}"
                : null;
        }

        $modules = array_map(function (array $mod) use ($webmailHost): array {
            $key = (string) ($mod['name'] ?? '');
            $cachedState = Cache::get("module_install_{$key}");
            $installed = (bool) ($mod['installed'] ?? false);

            $status = match (true) {
                in_array($cachedState, ['pending', 'provisioning', 'failed'], true) => $cachedState,
                $installed || $cachedState === 'active' => 'active',
                default => 'inactive',
            };

            $isActive = $status === 'active';
            $errorMessage = $status === 'failed'
                ? (is_string($err = Cache::get("module_install_{$key}_error")) ? $err : 'Installation failed.')
                : null;

            $launchUrl = ($key === 'mail_webmail' && $isActive && is_string($webmailHost) && $webmailHost !== '')
                ? "https://{$webmailHost}/"
                : null;

            return [
                'key' => $key,
                'name' => $key,
                'title' => (string) ($mod['title'] ?? $key),
                'description' => (string) ($mod['description'] ?? ''),
                'driver' => (string) ($mod['driver'] ?? $key),
                'parent_key' => $mod['parent'] ?? null,
                'depends_on' => is_array($mod['depends_on'] ?? null) ? array_values($mod['depends_on']) : [],
                'status' => $status,
                'is_active' => $isActive,
                'installed' => $isActive,
                'error_message' => $errorMessage,
                'launch_url' => $launchUrl,
            ];
        }, $rawModules);

        $current = Cache::get('module_install_current');
        $rawStatus = $current ? Cache::get("module_install_{$current}") : null;
        $installingModule = in_array($rawStatus, ['pending', 'provisioning'], true) ? $current : null;

        $stats = $this->serverDriver->getStats();
        $serverRamMb = (int) ($stats['ram_total_mb'] ?? 0);

        return Inertia::render('ServerOps/Modules', [
            'modules' => $modules,
            'server_ram_mb' => $serverRamMb,
            'installingModule' => $installingModule,
        ]);
    }

    /**
     * Install an optional module on-demand.
     */
    public function install(Request $request, string $module, InstallModuleAction $installModuleAction)
    {
        if (! in_array($module, ModuleRegistry::allowedKeys(), true)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        if ($module === 'clamav') {
            $serverRamMb = (int) ($this->serverDriver->getStats()['ram_total_mb'] ?? 0);
            if ($serverRamMb < self::CLAMAV_MIN_RAM_MB) {
                return redirect()->back()->with(
                    'error',
                    sprintf(
                        'ClamAV requires at least %d MB of server RAM (detected %d MB).',
                        self::CLAMAV_MIN_RAM_MB,
                        $serverRamMb
                    )
                );
            }
        }

        $hostname = null;
        if (in_array($module, ['mail_core', 'mail_webmail'], true)) {
            $validated = $request->validate([
                'hostname' => [
                    'required',
                    'string',
                    'max:253',
                    'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                ],
            ], [
                'hostname.required' => 'A valid FQDN hostname is required.',
                'hostname.regex' => 'Hostname must be a valid fully qualified domain name (e.g. mail.example.com).',
            ]);
            $hostname = $validated['hostname'];
        }

        try {
            $installModuleAction->handle($module, Auth::user(), $request->ip(), $hostname);

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
            'log' => $this->moduleCli->getModuleInstallLog($module),
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
        if (! in_array($module, ModuleRegistry::allowedKeys(), true)) {
            return redirect()->back()->with('error', "Unsupported module '$module'.");
        }

        try {
            $removeModuleAction->handle(
                $module,
                Auth::user(),
                $request->ip(),
                $request->boolean('purge_data')
            );

            return redirect()->back()->with('success', "Module '$module' removed successfully.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
