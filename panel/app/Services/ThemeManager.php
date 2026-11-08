<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ThemeManager
{
    protected string $fallback = 'avionics';

    /**
     * Return all discovered theme manifests indexed by theme ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(): array
    {
        $themes = [];
        $pattern = resource_path('js/Themes/*/theme.json');

        foreach (File::glob($pattern) as $path) {
            $manifest = json_decode(File::get($path), true);
            if (is_array($manifest) && !empty($manifest['id'])) {
                $themes[$manifest['id']] = $manifest;
            }
        }

        return $themes;
    }

    /**
     * Resolve the active theme manifest for the current request.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return array<string, mixed>
     */
    public function resolve(?Request $request = null): array
    {
        $available = $this->available();
        $fallbackId = config('themes.fallback', $this->fallback);

        $candidate = $request?->user()?->theme
            ?? $request?->session()?->get('theme')
            ?? config('themes.default', $fallbackId);

        if (isset($available[$candidate])) {
            return $available[$candidate];
        }

        if (isset($available[$fallbackId])) {
            return $available[$fallbackId];
        }

        return reset($available) ?: [
            'id' => 'avionics',
            'name' => 'Avionics Telemetry',
            'description' => 'Tactical avionics interface with monospace instrumentation.',
            'layout_type' => 'vertical-sidebar',
            'color_scheme' => 'dark',
            'progress_color' => '#ff6b35',
            'fonts' => [],
        ];
    }
}
