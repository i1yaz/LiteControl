<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;

final class GetDeploymentPipelineAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService,
    ) {}

    /**
     * Build the deterministic pipeline read payload for a deployment configuration.
     *
     * @return array{
     *     stages: list<array<string, mixed>>,
     *     slots: list<array{key: string, label: string, allows_release_placeholder: bool}>,
     *     placeholders: list<array{token: string, description: string, available_in_before_clone: bool}>,
     *     install_script: ?string,
     *     hooks: list<array{
     *         id: int,
     *         config_id: int,
     *         name: string,
     *         slot: string,
     *         script: string,
     *         sort_order: int,
     *         is_active: bool
     *     }>
     * }
     */
    public function execute(DomainDeploymentConfig $config): array
    {
        $slotOrderSql = $this->buildSlotOrderCaseSql();

        $hooks = DomainDeploymentHook::query()
            ->where('config_id', $config->id)
            ->orderByRaw($slotOrderSql)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (DomainDeploymentHook $hook): array => [
                'id' => (int) $hook->id,
                'config_id' => (int) $hook->config_id,
                'name' => (string) $hook->name,
                'slot' => $hook->slot instanceof DeploymentHookSlot
                    ? $hook->slot->value
                    : (string) ($hook->slot ?: DeploymentHookSlot::default()->value),
                'script' => (string) $hook->script,
                'sort_order' => (int) $hook->sort_order,
                'is_active' => (bool) $hook->is_active,
            ])
            ->values()
            ->all();

        return [
            'stages' => $this->pipelineService->defaultStages($config->install_script),
            'slots' => $this->pipelineService->slots(),
            'placeholders' => $this->pipelineService->placeholders(),
            'install_script' => $config->install_script,
            'hooks' => $hooks,
        ];
    }

    private function buildSlotOrderCaseSql(): string
    {
        $clauses = [];
        foreach (DeploymentHookSlot::cases() as $index => $slot) {
            $clauses[] = "WHEN '{$slot->value}' THEN {$index}";
        }

        return 'CASE slot '.implode(' ', $clauses).' ELSE 99 END';
    }
}
