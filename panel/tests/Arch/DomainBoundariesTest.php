<?php

declare(strict_types=1);

use App\Support\Cli\LcCliService;

arch('controllers do not use LcCliService or Domain Infrastructure')
    ->expect('App\Http\Controllers')
    ->not->toUse(LcCliService::class)
    ->not->toUse('App\Domains\*\Infrastructure');

arch('domain actions do not use LcCliService or Infrastructure')
    ->expect('App\Domains\*\Actions')
    ->not->toUse(LcCliService::class)
    ->not->toUse('App\Domains\*\Infrastructure');

arch('domain value objects are readonly')
    ->expect('App\Domains\*\ValueObjects')
    ->toBeReadonly();

arch('domains do not import from other domains')
    ->expect('App\Domains')
    ->not->toUse('App\Domains')
    ->ignoring('App\Domains\*\Events');

