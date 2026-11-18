<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Services;

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
     *     hooks: list<array{name: string, stage: string, position: string, script: string, sort_order: int}>
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
                'hooks' => [
                    [
                        'name' => 'Install Composer Dependencies',
                        'stage' => 'install',
                        'position' => 'run',
                        'script' => '{{composer}} install --no-dev --optimize-autoloader --no-interaction',
                        'sort_order' => 10,
                    ],
                    [
                        'name' => 'Run Database Migrations',
                        'stage' => 'install',
                        'position' => 'after',
                        'script' => '{{php}} {{release}}/artisan migrate --force',
                        'sort_order' => 20,
                    ],
                    [
                        'name' => 'Cache Configuration',
                        'stage' => 'install',
                        'position' => 'after',
                        'script' => '{{php}} {{release}}/artisan config:cache',
                        'sort_order' => 30,
                    ],
                    [
                        'name' => 'Cache Routes',
                        'stage' => 'install',
                        'position' => 'after',
                        'script' => '{{php}} {{release}}/artisan route:cache',
                        'sort_order' => 40,
                    ],
                    [
                        'name' => 'Cache Views',
                        'stage' => 'install',
                        'position' => 'after',
                        'script' => '{{php}} {{release}}/artisan view:cache',
                        'sort_order' => 50,
                    ],
                    [
                        'name' => 'Restart Queue Workers',
                        'stage' => 'activate',
                        'position' => 'after',
                        'script' => '{{php}} {{release}}/artisan queue:restart',
                        'sort_order' => 60,
                    ],
                ],
            ],
            'wordpress' => [
                'label' => 'WordPress',
                'web_subpath' => '',
                'shared_dirs' => ['wp-content/uploads', 'wp-content/upgrade', 'wp-content/cache'],
                'shared_files' => ['wp-config.php', '.htaccess'],
                'hooks' => [],
            ],
            'node' => [
                'label' => 'Node.js',
                'web_subpath' => 'public',
                'shared_dirs' => ['storage', 'logs'],
                'shared_files' => ['.env'],
                'hooks' => [
                    [
                        'name' => 'Install NPM Dependencies',
                        'stage' => 'install',
                        'position' => 'run',
                        'script' => 'npm ci',
                        'sort_order' => 10,
                    ],
                    [
                        'name' => 'Build Application',
                        'stage' => 'install',
                        'position' => 'after',
                        'script' => 'npm run build',
                        'sort_order' => 20,
                    ],
                ],
            ],
            'custom' => [
                'label' => 'Custom',
                'web_subpath' => '',
                'shared_dirs' => [],
                'shared_files' => ['.env'],
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
     *     hooks: list<array{name: string, stage: string, position: string, script: string, sort_order: int}>
     * }
     */
    public function get(string $preset): array
    {
        $presets = $this->all();

        return $presets[$preset] ?? $presets['custom'];
    }
}
