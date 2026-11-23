<?php

namespace App\Http\Middleware;

use App\Domains\PanelConfig\Queries\ThemeManager;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $themeManager = app(ThemeManager::class);
        $activeTheme = $themeManager->resolve($request);
        $availableThemes = array_values($themeManager->available());

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ?? 'user',
                    'status' => $user->status ?? 'active',
                    'avatar' => $user->avatar,
                    'theme' => $user->theme,
                ] : null,
                'is_impersonating' => (bool) $request->session()->has('impersonator_id'),
            ],
            'php' => [
                'supported_versions' => PhpVersion::SUPPORTED,
            ],
            'modules' => fn () => $user ? [
                'mail_core' => app(ModuleRegistryQueryInterface::class)->isActive('mail_core'),
                'dns' => app(ModuleRegistryQueryInterface::class)->isActive('dns'),
                'ftp' => app(ModuleRegistryQueryInterface::class)->isActive('ftp'),
            ] : [
                'mail_core' => false,
                'dns' => false,
                'ftp' => false,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'message' => fn () => $request->session()->get('message'),
                'missing_php_version' => fn () => $request->session()->get('missing_php_version'),
            ],
            'panel' => [
                'name' => config('app.name', 'LiteControl'),
                'version' => '1.0.0',
                'is_mock_mode' => (bool) env('LC_DEV_MOCK', true),
                'theme' => $activeTheme,
                'available_themes' => $availableThemes,
                'server_ip' => fn () => $this->resolveServerIp($request),
            ],
            'locale' => App::getLocale(),
            'available_locales' => collect(config('app.available_locales', []))
                ->map(fn (array $meta, string $code) => [
                    'code' => $code,
                    'name' => $meta['name'] ?? $code,
                    'native' => $meta['native'] ?? $code,
                    'dir' => $meta['dir'] ?? 'ltr',
                ])
                ->values()
                ->all(),
            'translations' => fn () => $this->loadTranslations(App::getLocale()),
        ];
    }

    /**
     * Load JSON translations for the active locale with English fallback.
     *
     * @return array<string, string>
     */
    protected function loadTranslations(string $locale): array
    {
        $fallbackPath = lang_path('en.json');
        $fallback = is_file($fallbackPath)
            ? (json_decode((string) file_get_contents($fallbackPath), true) ?: [])
            : [];

        if ($locale === 'en') {
            return $fallback;
        }

        $localePath = lang_path("{$locale}.json");
        $current = is_file($localePath)
            ? (json_decode((string) file_get_contents($localePath), true) ?: [])
            : [];

        return array_merge($fallback, $current);
    }

    /**
     * Resolve the server's public IP address, falling back to ServerDriverInterface
     * when behind the local OpenLiteSpeed reverse proxy (SERVER_ADDR = 127.0.0.1 / ::1).
     */
    protected function resolveServerIp(Request $request): string
    {
        $serverAddr = (string) $request->server('SERVER_ADDR', '');

        if ($serverAddr !== '' && ! in_array($serverAddr, ['127.0.0.1', '::1'], true)) {
            return $serverAddr;
        }

        $cached = Cache::get('panel.server_ip');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $stats = app(ServerDriverInterface::class)->getStats();
            $resolved = trim((string) ($stats['server_ip'] ?? ''));

            if ($resolved !== '' && ! in_array($resolved, ['127.0.0.1', '::1'], true)) {
                Cache::put('panel.server_ip', $resolved, now()->addMinutes(10));

                return $resolved;
            }
        } catch (\Throwable) {
            // Fall through to default below so a CLI failure never breaks page rendering.
        }

        return $serverAddr !== '' ? $serverAddr : '127.0.0.1';
    }
}
