<?php

declare(strict_types=1);

use App\Domains\Deployment\Services\DeploymentPresetService;

it('returns structured defaults for all supported presets', function () {
    $service = new DeploymentPresetService;
    $presets = $service->all();

    expect($presets)->toHaveKeys(['laravel', 'wordpress', 'node', 'custom']);

    expect($presets['laravel'])->toMatchArray([
        'web_subpath' => 'public',
        'shared_dirs' => ['storage'],
        'shared_files' => ['.env'],
    ]);
    expect($presets['laravel']['hooks'])->toHaveCount(6);

    expect($presets['wordpress'])->toMatchArray([
        'web_subpath' => '',
        'shared_dirs' => ['wp-content/uploads', 'wp-content/upgrade', 'wp-content/cache'],
        'shared_files' => ['wp-config.php', '.htaccess'],
        'hooks' => [],
    ]);

    expect($presets['node'])->toMatchArray([
        'web_subpath' => 'public',
        'shared_dirs' => ['storage', 'logs'],
        'shared_files' => ['.env'],
    ]);
    expect($presets['node']['hooks'])->toHaveCount(2);

    expect($presets['custom'])->toMatchArray([
        'web_subpath' => '',
        'shared_dirs' => [],
        'shared_files' => ['.env'],
        'hooks' => [],
    ]);
});

it('falls back to custom preset for unknown preset names', function () {
    $service = new DeploymentPresetService;

    expect($service->get('unknown-framework'))->toBe($service->get('custom'));
});
