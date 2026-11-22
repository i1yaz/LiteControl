<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\ServerOps\ModuleRegistry;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleInstalled
{
    public function __construct(
        private readonly ModuleRegistryQueryInterface $moduleRegistry,
    ) {}

    /**
     * Ensure the required optional module is installed/active before allowing access.
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        if ($this->moduleRegistry->isActive($moduleKey)) {
            return $next($request);
        }

        $definitions = ModuleRegistry::definitions();
        $moduleTitle = $definitions[$moduleKey]['title'] ?? $moduleKey;

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => "The {$moduleTitle} module is not installed.",
                'module' => $moduleKey,
            ], 403);
        }

        $user = $request->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

        if ($isAdmin) {
            return redirect()
                ->route('modules.index')
                ->with('warning', "The {$moduleTitle} module must be installed before accessing this page.");
        }

        return redirect()
            ->route('dashboard')
            ->with('warning', "The {$moduleTitle} module is not installed on this server. Please contact your administrator.");
    }
}
