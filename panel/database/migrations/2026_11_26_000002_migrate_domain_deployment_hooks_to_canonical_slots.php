<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates domain_deployment_hooks from the legacy 8-slot (stage + position) model
 * to the canonical 5-slot + 0-indexed sort_order model.
 *
 * Mapping & Ordering Invariants:
 * - Stage 1 Before (clone/before)                        -> before_clone   (priority 0)
 * - Stage 1 After  (clone/after, clone/run)              -> after_clone    (priority 0)
 * - Stage 2 Before (install|build/before)                -> after_clone    (priority 1)
 * - Stage 2 Run    (install|build/run) [legacy preset]   -> after_clone    (priority 2)
 * - Stage 2 After  (install|build/after)                 -> after_install  (priority 0)
 * - Stage 3 Before (activate/before)                     -> after_install  (priority 1)
 * - Stage 3 After  (activate/after, activate/run)        -> after_activate (priority 0)
 * - Stage 4 Before (cleanup|purge/before)                -> after_activate (priority 1)
 * - Stage 4 After  (cleanup|purge/after, cleanup|purge/run) -> after_purge (priority 0)
 * - Unrecognized or null legacy values                   -> after_purge    (priority 1)
 *
 * Assumptions:
 * - Legacy `stage` and `position` columns are retained as nullable during Phase 1
 *   so existing controllers, payload builders, and tests remain non-breaking until
 *   Phases 2-3 transition to `slot`.
 * - Within each (config_id, slot), rows are deterministically ordered by:
 *   (merge_priority ASC, legacy sort_order ASC with NULLs last, id ASC),
 *   then re-indexed sequentially starting at 0.
 * - Legacy `(install|build, run)` hooks are preserved non-destructively in `after_clone`
 *   after `install/before` so no rows are dropped and execution order is preserved.
 */
return new class extends Migration
{
    private const CANONICAL_SLOTS = [
        'before_clone',
        'after_clone',
        'after_install',
        'after_activate',
        'after_purge',
    ];

    public function up(): void
    {
        Schema::table('domain_deployment_hooks', function (Blueprint $table): void {
            $table->string('slot', 24)->default('after_purge')->after('name');
            $table->string('stage', 20)->nullable()->change();
            $table->string('position', 10)->nullable()->change();
            $table->unsignedInteger('sort_order')->default(0)->change();

            $table->index(['config_id', 'slot', 'sort_order'], 'dd_hooks_config_slot_sort_idx');
        });

        $this->backfillCanonicalSlots();
    }

    public function down(): void
    {
        Schema::table('domain_deployment_hooks', function (Blueprint $table): void {
            $table->dropIndex('dd_hooks_config_slot_sort_idx');
            $table->dropColumn('slot');
        });
    }

    private function backfillCanonicalSlots(): void
    {
        $rows = DB::table('domain_deployment_hooks')
            ->select(['id', 'config_id', 'stage', 'position', 'slot', 'sort_order'])
            ->orderBy('config_id')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        /** @var array<int, array<string, list<array{id: int, priority: int, legacy_sort: int}>>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            [$slot, $priority] = $this->resolveLegacySlot(
                stage: is_string($row->stage) ? $row->stage : null,
                position: is_string($row->position) ? $row->position : null,
                existingSlot: is_string($row->slot) ? $row->slot : null,
            );

            $configId = (int) $row->config_id;
            $legacySort = $row->sort_order === null ? PHP_INT_MAX : (int) $row->sort_order;

            $grouped[$configId][$slot][] = [
                'id' => (int) $row->id,
                'priority' => $priority,
                'legacy_sort' => $legacySort,
            ];
        }

        DB::transaction(function () use ($grouped): void {
            foreach ($grouped as $slots) {
                foreach ($slots as $slot => $items) {
                    usort($items, static function (array $a, array $b): int {
                        return $a['priority'] <=> $b['priority']
                            ?: $a['legacy_sort'] <=> $b['legacy_sort']
                            ?: $a['id'] <=> $b['id'];
                    });

                    foreach ($items as $index => $item) {
                        DB::table('domain_deployment_hooks')
                            ->where('id', $item['id'])
                            ->update([
                                'slot' => $slot,
                                'sort_order' => $index,
                            ]);
                    }
                }
            }
        });
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveLegacySlot(?string $stage, ?string $position, ?string $existingSlot): array
    {
        $normalizedStage = strtolower(trim((string) $stage));
        $normalizedPosition = strtolower(trim((string) $position));

        if ($normalizedStage === '' && $normalizedPosition === '' && $existingSlot !== null && in_array($existingSlot, self::CANONICAL_SLOTS, true)) {
            return [$existingSlot, 0];
        }

        return match ($normalizedStage) {
            'clone' => match ($normalizedPosition) {
                'before' => ['before_clone', 0],
                'after', 'run' => ['after_clone', 0],
                default => ['after_purge', 1],
            },
            'install', 'build' => match ($normalizedPosition) {
                'before' => ['after_clone', 1],
                'run' => ['after_clone', 2],
                'after' => ['after_install', 0],
                default => ['after_purge', 1],
            },
            'activate' => match ($normalizedPosition) {
                'before' => ['after_install', 1],
                'after', 'run' => ['after_activate', 0],
                default => ['after_purge', 1],
            },
            'cleanup', 'purge' => match ($normalizedPosition) {
                'before' => ['after_activate', 1],
                'after', 'run' => ['after_purge', 0],
                default => ['after_purge', 1],
            },
            default => ['after_purge', 1],
        };
    }
};
