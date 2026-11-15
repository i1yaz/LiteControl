<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Update the active locale in session and, if authenticated, on the user record.
     */
    public function update(Request $request): RedirectResponse
    {
        $available = array_keys(config('app.available_locales', ['en' => []]));

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($available)],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return back();
    }
}
