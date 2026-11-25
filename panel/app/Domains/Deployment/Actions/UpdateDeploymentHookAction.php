<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateDeploymentHookAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(
        DomainDeploymentConfig $config,
        DomainDeploymentHook $hook,
        string $name,
        string $script,
        ?DeploymentHookSlot $slot = null,
        ?bool $isActive = null,
    ): DomainDeploymentHook {
        abort_unless((int) $hook->config_id === (int) $config->id, 404);

        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw ValidationException::withMessages([
                'name' => 'The hook name is required.',
            ]);
        }

        $normalizedScript = $this->pipelineService->normalizeScript($script);
        if ($normalizedScript === null) {
            throw ValidationException::withMessages([
                'script' => 'The hook script cannot be empty.',
            ]);
        }

        $currentSlot = $hook->slot instanceof DeploymentHookSlot
            ? $hook->slot
            : DeploymentHookSlot::default();
        $resolvedSlot = $slot ?? $currentSlot;

        $this->pipelineService->assertSlotAllowsScript($resolvedSlot, $normalizedScript, 'script');

        return DB::transaction(function () use ($config, $hook, $trimmedName, $normalizedScript, $currentSlot, $resolvedSlot, $isActive): DomainDeploymentHook {
            $attributes = [
                'name' => $trimmedName,
                'slot' => $resolvedSlot,
                'script' => $normalizedScript,
            ];

            if ($isActive !== null) {
                $attributes['is_active'] = $isActive;
            }

            if ($resolvedSlot !== $currentSlot) {
                $maxOrder = DomainDeploymentHook::query()
                    ->where('config_id', $config->id)
                    ->where('slot', $resolvedSlot->value)
                    ->where('id', '!=', $hook->id)
                    ->max('sort_order');

                $attributes['sort_order'] = $maxOrder === null ? 0 : ((int) $maxOrder) + 1;
            }

            $hook->update($attributes);

            return $hook->refresh();
        });
    }
}
