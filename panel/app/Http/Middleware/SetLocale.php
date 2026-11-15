<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Resolve and set the application locale for the current request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('app.available_locales', ['en' => []]));
        $default = config('app.locale', 'en');

        $candidate = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage($available)
            ?? $default;

        $locale = in_array($candidate, $available, true) ? $candidate : $default;

        App::setLocale($locale);

        return $next($request);
    }
}
