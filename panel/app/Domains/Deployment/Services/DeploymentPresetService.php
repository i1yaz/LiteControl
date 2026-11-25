<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Services;

use App\Domains\Deployment\Enums\DeploymentHookSlot;

final class DeploymentPresetService
{
    /**
     * Return all available framework presets keyed by preset identifier.
     *
     * @return array<string, array{
     *     label: string,
     *     web_subpath: string,
     *     shared_dirs: list<string>,
     *     shared_files: list<string>,
     *     install_script: ?string,
     *     hooks: list<array{name: string, slot: string, script: string, sort_order: int}>
     * }>
     */
    public function all(): array
    {
        return [
            'laravel' => [
                'label' => 'Laravel',
                'web_subpath' => 'public',
                'shared_dirs' => ['storage'],
                'shared_files' => ['.env'],
                'install_script' => '{{composer}} install --no-dev --optimize-autoloader --no-interaction',
                'hooks' => [
                    [
                        'name' => 'Run Database Migrations',
                        'slot' => DeploymentHookSlot::AfterInstall->value,
                        'script' => '{{php}} {{release}}/artisan migrate --force',
                        'sort_order' => 0,
                    ],
                    [
                        'name' => 'Cache Configuration',
                        'slot' => DeploymentHookSlot::AfterInstall->value,
                        'script' => '{{php}} {{release}}/artisan config:cache',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Cache Routes',
                        'slot' => DeploymentHookSlot::AfterInstall->value,
                        'script' => '{{php}} {{release}}/artisan route:cache',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Cache Views',
                        'slot' => DeploymentHookSlot::AfterInstall->value,
                        'script' => '{{php}} {{release}}/artisan view:cache',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Restart Queue Workers',
                        'slot' => DeploymentHookSlot::AfterActivate->value,
                        'script' => '{{php}} {{release}}/artisan queue:restart',
                        'sort_order' => 0,
                    ],
                ],
            ],
            'wordpress' => [
                'label' => 'WordPress',
                'web_subpath' => '',
                'shared_dirs' => ['wp-content/uploads', 'wp-content/upgrade', 'wp-content/cache'],
                'shared_files' => ['wp-config.php', '.htaccess'],
                'install_script' => null,
                'hooks' => [],
            ],
            'node' => [
                'label' => 'Node.js',
                'web_subpath' => 'public',
                'shared_dirs' => ['storage', 'logs'],
                'shared_files' => ['.env'],
                'install_script' => "npm ci\nnpm run build",
                'hooks' => [],
            ],
            'custom' => [
                'label' => 'Custom',
                'web_subpath' => '',
                'shared_dirs' => [],
                'shared_files' => ['.env'],
                'install_script' => null,
                'hooks' => [],
            ],
        ];
    }

    /**
     * Get configuration defaults for a specific preset, falling back to custom.
     *
     * @return array{
     *     label: string,
     *     web_subpath: string,
     *     shared_dirs: list<string>,
     *     shared_files: list<string>,
     *     install_script: ?string,
     *     hooks: list<array{name: string, slot: string, script: string, sort_order: int}>
     * }
     */
    public function get(string $preset): array
    {
        $presets = $this->all();

        return $presets[$preset] ?? $presets['custom'];
    }
}
