<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReorderDeploymentHooksAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService,
    ) {}

    /**
     * Validate and persist reordered hook slots & sort_order values in a single bulk UPDATE.
     *
     * @param  list<array{id: int|string, slot: string, sort_order: int|string}>  $items
     *
     * @throws ValidationException
     */
    public function execute(DomainDeploymentConfig $config, array $items): void
    {
        DB::transaction(function () use ($config, $items): void {
            $existingHooks = DomainDeploymentHook::query()
                ->where('config_id', $config->id)
                ->get(['id', 'name', 'slot', 'script'])
                ->keyBy('id');

            $seenIds = [];
            $normalizedItems = [];

            foreach ($items as $index => $item) {
                $id = (int) $item['id'];
                $slotValue = (string) $item['slot'];
                $sortOrder = (int) $item['sort_order'];

                if (isset($seenIds[$id])) {
                    throw ValidationException::withMessages([
                        "hooks.{$index}.id" => "Duplicate hook ID {$id} is not allowed in reorder payload.",
                    ]);
                }
                $seenIds[$id] = true;

                /** @var ?DomainDeploymentHook $hook */
                $hook = $existingHooks->get($id);
                if ($hook === null) {
                    throw ValidationException::withMessages([
                        "hooks.{$index}.id" => "Hook ID {$id} does not belong to this domain's deployment pipeline.",
                    ]);
                }

                $slot = DeploymentHookSlot::tryFrom($slotValue);
                if ($slot === null) {
                    throw ValidationException::withMessages([
                        "hooks.{$index}.slot" => "Invalid hook slot '{$slotValue}'.",
                    ]);
                }

                if ($sortOrder < 0) {
                    throw ValidationException::withMessages([
                        "hooks.{$index}.sort_order" => 'Sort order must be a non-negative integer.',
                    ]);
                }

                if ($slot === DeploymentHookSlot::BeforeClone && $this->pipelineService->referencesReleasePlaceholder((string) $hook->script)) {
                    throw ValidationException::withMessages([
                        "hooks.{$index}.slot" => DeploymentPipelineService::BEFORE_CLONE_RELEASE_ERROR,
                    ]);
                }

                $normalizedItems[] = [
                    'id' => $id,
                    'slot' => $slot->value,
                    'sort_order' => $sortOrder,
                ];
            }

            if (count($normalizedItems) !== $existingHooks->count()) {
                throw ValidationException::withMessages([
                    'hooks' => 'Reorder payload must include every hook belonging to this domain.',
                ]);
            }

            if ($normalizedItems === []) {
                return;
            }

            $this->bulkUpdate($config->id, $normalizedItems);
        });
    }

    /**
     * Execute a single parameterized CASE-based bulk UPDATE to avoid N+1 writes.
     *
     * @param  list<array{id: int, slot: string, sort_order: int}>  $items
     */
    private function bulkUpdate(int $configId, array $items): void
    {
        $slotCases = [];
        $sortCases = [];
        $slotBindings = [];
        $sortBindings = [];
        $idPlaceholders = [];
        $idBindings = [];

        foreach ($items as $item) {
            $slotCases[] = 'WHEN ? THEN ?';
            $slotBindings[] = $item['id'];
            $slotBindings[] = $item['slot'];

            $sortCases[] = 'WHEN ? THEN ?';
            $sortBindings[] = $item['id'];
            $sortBindings[] = $item['sort_order'];

            $idPlaceholders[] = '?';
            $idBindings[] = $item['id'];
        }

        $now = now()->format('Y-m-d H:i:s');

        $sql = sprintf(
            'UPDATE domain_deployment_hooks SET slot = CASE id %s END, sort_order = CASE id %s END, updated_at = ? WHERE config_id = ? AND id IN (%s)',
            implode(' ', $slotCases),
            implode(' ', $sortCases),
            implode(', ', $idPlaceholders),
        );

        $bindings = array_merge(
            $slotBindings,
            $sortBindings,
            [$now, $configId],
            $idBindings,
        );

        DB::update($sql, $bindings);
    }
}
