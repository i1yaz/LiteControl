<?php

declare(strict_types=1);

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

it('normalizes script line endings and treats whitespace-only input as null', function () {
    $service = new DeploymentPipelineService;

    expect($service->normalizeScript(null))->toBeNull();
    expect($service->normalizeScript(''))->toBeNull();
    expect($service->normalizeScript("   \r\n\t  \n "))->toBeNull();
    expect($service->normalizeScript("echo one\r\necho two\recho three"))
        ->toBe("echo one\necho two\necho three");
});

it('detects release placeholder references accurately', function () {
    $service = new DeploymentPipelineService;

    expect($service->referencesReleasePlaceholder('cd {{release}} && php artisan migrate'))->toBeTrue();
    expect($service->referencesReleasePlaceholder('ls {{ release }}'))->toBeTrue();
    expect($service->referencesReleasePlaceholder('echo {{RELEASE}}'))->toBeTrue();
    expect($service->referencesReleasePlaceholder('cd {{project}} && echo {{shared}}'))->toBeFalse();
});

it('enforces the before_clone release placeholder invariant', function () {
    $service = new DeploymentPipelineService;

    expect(fn () => $service->assertSlotAllowsScript(
        DeploymentHookSlot::BeforeClone,
        '{{php}} {{release}}/artisan down',
    ))->toThrow(ValidationException::class);

    // Allowed in before_clone without {{release}}
    $service->assertSlotAllowsScript(DeploymentHookSlot::BeforeClone, 'echo "Starting in {{project}}"');

    // Allowed in all other canonical slots with {{release}}
    foreach ([
        DeploymentHookSlot::AfterClone,
        DeploymentHookSlot::AfterInstall,
        DeploymentHookSlot::AfterActivate,
        DeploymentHookSlot::AfterPurge,
    ] as $slot) {
        $service->assertSlotAllowsScript($slot, 'cd {{release}} && echo ok');
    }

    expect(true)->toBeTrue();
});

it('returns 4 locked default stages, 5 canonical slots, and 7 supported placeholders', function () {
    $service = new DeploymentPipelineService;

    $stages = $service->defaultStages('composer install --no-dev');
    expect($stages)->toHaveCount(4);
    expect(array_column($stages, 'key'))->toBe(['clone', 'install', 'activate', 'purge']);
    expect($stages[0]['locked'])->toBeTrue();
    expect($stages[1]['editable'])->toBeTrue();
    expect($stages[1]['script'])->toBe('composer install --no-dev');

    $slots = $service->slots();
    expect(array_column($slots, 'key'))->toBe([
        'before_clone',
        'after_clone',
        'after_install',
        'after_activate',
        'after_purge',
    ]);
    expect($slots[0]['allows_release_placeholder'])->toBeFalse();
    expect($slots[1]['allows_release_placeholder'])->toBeTrue();

    $placeholders = $service->placeholders();
    expect(array_column($placeholders, 'token'))->toBe([
        '{{release}}',
        '{{project}}',
        '{{shared}}',
        '{{php}}',
        '{{composer}}',
        '{{branch}}',
        '{{sha}}',
    ]);
    expect($placeholders[0]['available_in_before_clone'])->toBeFalse();
    expect($placeholders[1]['available_in_before_clone'])->toBeTrue();
});
