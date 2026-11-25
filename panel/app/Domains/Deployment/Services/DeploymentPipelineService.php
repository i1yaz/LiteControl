<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Services;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use Illuminate\Validation\ValidationException;

final class DeploymentPipelineService
{
    public const BEFORE_CLONE_RELEASE_ERROR = 'Hooks in the before_clone slot cannot reference {{release}} because the release directory does not exist before cloning.';

    /**
     * Normalize line endings (\r\n and \r -> \n) and treat whitespace-only input as null.
     */
    public function normalizeScript(?string $script): ?string
    {
        if ($script === null) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $script);

        if (trim($normalized) === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Check whether a script references the {{release}} placeholder.
     */
    public function referencesReleasePlaceholder(string $script): bool
    {
        return preg_match('/\{\{\s*release\s*\}\}/i', $script) === 1;
    }

    /**
     * Enforce the before_clone invariant: {{release}} cannot be used before cloning.
     *
     * @throws ValidationException
     */
    public function assertSlotAllowsScript(DeploymentHookSlot $slot, string $script, string $errorField = 'script'): void
    {
        if ($slot === DeploymentHookSlot::BeforeClone && $this->referencesReleasePlaceholder($script)) {
            throw ValidationException::withMessages([
                $errorField => self::BEFORE_CLONE_RELEASE_ERROR,
            ]);
        }
    }

    /**
     * Return the 4 locked default stage definitions in execution order.
     *
     * @return list<array{
     *     step: int,
     *     key: string,
     *     title: string,
     *     description: string,
     *     locked: bool,
     *     editable: bool,
     *     script?: ?string
     * }>
     */
    public function defaultStages(?string $installScript): array
    {
        return [
            [
                'step' => 1,
                'key' => 'clone',
                'title' => 'Clone Release',
                'description' => 'Fetch clean archive for target ref (no .git leftovers) & symlink shared paths.',
                'locked' => true,
                'editable' => false,
            ],
            [
                'step' => 2,
                'key' => 'install',
                'title' => 'Install Dependencies',
                'description' => 'Run optional dependency installation and build commands inside the release directory.',
                'locked' => true,
                'editable' => true,
                'script' => $installScript,
            ],
            [
                'step' => 3,
                'key' => 'activate',
                'title' => 'Activate Release',
                'description' => 'Atomic symlink swap, detached PHP-FPM/LSPHP reload & HTTP health check.',
                'locked' => true,
                'editable' => false,
            ],
            [
                'step' => 4,
                'key' => 'purge',
                'title' => 'Purge Old Releases',
                'description' => 'Prune expired release directories beyond the retention limit.',
                'locked' => true,
                'editable' => false,
            ],
        ];
    }

    /**
     * Return canonical slot metadata in execution order.
     *
     * @return list<array{key: string, label: string, allows_release_placeholder: bool}>
     */
    public function slots(): array
    {
        return array_map(
            static fn (DeploymentHookSlot $slot): array => [
                'key' => $slot->value,
                'label' => $slot->label(),
                'allows_release_placeholder' => $slot !== DeploymentHookSlot::BeforeClone,
            ],
            DeploymentHookSlot::cases(),
        );
    }

    /**
     * Return supported template placeholder metadata.
     *
     * @return list<array{token: string, description: string, available_in_before_clone: bool}>
     */
    public function placeholders(): array
    {
        return [
            [
                'token' => '{{release}}',
                'description' => 'Absolute path to the active release directory (not available in before_clone).',
                'available_in_before_clone' => false,
            ],
            [
                'token' => '{{project}}',
                'description' => 'Absolute path to the domain project root directory.',
                'available_in_before_clone' => true,
            ],
            [
                'token' => '{{shared}}',
                'description' => 'Absolute path to the persistent shared directory.',
                'available_in_before_clone' => true,
            ],
            [
                'token' => '{{php}}',
                'description' => 'Configured PHP CLI binary path for this domain.',
                'available_in_before_clone' => true,
            ],
            [
                'token' => '{{composer}}',
                'description' => 'Configured Composer command using the domain PHP binary.',
                'available_in_before_clone' => true,
            ],
            [
                'token' => '{{branch}}',
                'description' => 'Git branch being deployed.',
                'available_in_before_clone' => true,
            ],
            [
                'token' => '{{sha}}',
                'description' => 'Target commit SHA (available once resolved).',
                'available_in_before_clone' => true,
            ],
        ];
    }
}
