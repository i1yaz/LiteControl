<?php

namespace App\Http\Middleware;

use App\Domains\PanelConfig\Services\ThemeManager;
use Illuminate\Http\Request;
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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'message' => fn () => $request->session()->get('message'),
            ],
            'panel' => [
                'name' => config('app.name', 'LiteControl'),
                'version' => '1.0.0',
                'is_mock_mode' => (bool) env('LC_DEV_MOCK', true),
                'theme' => $activeTheme,
                'available_themes' => $availableThemes,
                'server_ip' => $request->server('SERVER_ADDR', '127.0.0.1'),
            ],
        ];
    }
}
