<?php

namespace App\Http\Controllers;

use App\Domains\PanelConfig\Services\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    /**
     * Switch the active user or session interface theme.
     */
    public function switch(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validIds = array_keys($themes->available());
        if (empty($validIds)) {
            $validIds = ['avionics', 'horizon'];
        }

        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in($validIds)],
        ]);

        $themeId = $validated['theme'];

        if ($user = $request->user()) {
            $user->update(['theme' => $themeId]);
        }

        $request->session()->put('theme', $themeId);

        return redirect()->back()->with('success', 'Interface theme updated.');
    }
}
