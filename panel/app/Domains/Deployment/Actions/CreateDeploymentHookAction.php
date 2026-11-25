<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDeploymentHookAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(
        DomainDeploymentConfig $config,
        string $name,
        string $script,
        ?DeploymentHookSlot $slot = null,
        bool $isActive = true,
    ): DomainDeploymentHook {
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

        $resolvedSlot = $slot ?? DeploymentHookSlot::default();
        $this->pipelineService->assertSlotAllowsScript($resolvedSlot, $normalizedScript, 'script');

        return DB::transaction(function () use ($config, $trimmedName, $normalizedScript, $resolvedSlot, $isActive): DomainDeploymentHook {
            $maxOrder = DomainDeploymentHook::query()
                ->where('config_id', $config->id)
                ->where('slot', $resolvedSlot->value)
                ->max('sort_order');

            $nextOrder = $maxOrder === null ? 0 : ((int) $maxOrder) + 1;

            return DomainDeploymentHook::query()->create([
                'config_id' => $config->id,
                'name' => $trimmedName,
                'slot' => $resolvedSlot,
                'script' => $normalizedScript,
                'sort_order' => $nextOrder,
                'is_active' => $isActive,
            ]);
        });
    }
}
