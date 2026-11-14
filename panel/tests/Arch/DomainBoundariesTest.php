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

$domains = [
    'Backup',
    'Database',
    'HostingPlans',
    'Identity',
    'PanelConfig',
    'PhpRuntime',
    'Security',
    'ServerOps',
    'WebServer',
];

foreach ($domains as $domain) {
    $otherDomains = array_values(array_filter($domains, fn (string $d): bool => $d !== $domain));

    $forbiddenTargets = [];
    foreach ($otherDomains as $other) {
        $forbiddenTargets[] = "App\\Domains\\{$other}\\Actions";
        $forbiddenTargets[] = "App\\Domains\\{$other}\\Infrastructure";
        $forbiddenTargets[] = "App\\Domains\\{$other}\\Models";
    }

    arch("{$domain} domain respects bounded context boundaries")
        ->expect("App\\Domains\\{$domain}")
        ->not->toUse($forbiddenTargets)
        ->ignoring([
            'App\Domains\PanelConfig\Models\AuditLog',
            'App\Domains\Identity\Models\User',
            'App\Domains\HostingPlans\Models\Package',
        ]);
}
