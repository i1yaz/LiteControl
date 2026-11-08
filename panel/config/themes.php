<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Panel Theme
    |--------------------------------------------------------------------------
    |
    | This value is the default theme for users who have not chosen a personal
    | preference, and for unauthenticated views.
    |
    */
    'default' => env('LC_DEFAULT_THEME', 'avionics'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Theme
    |--------------------------------------------------------------------------
    |
    | The safe hard-fallback theme used if the chosen theme or default theme
    | cannot be found or is invalid.
    |
    */
    'fallback' => 'avionics',
];
