<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final contract migration for the 4-stage / 5-slot deployment pipeline.
 *
 * - Defensively backfills any invalid/NULL `slot` or `sort_order` rows and asserts invariants.
 * - Tightens `slot` and `sort_order` as NOT NULL and ensures the composite index
 *   `dd_hooks_config_slot_sort_idx` exists on (config_id, slot, sort_order).
 * - Drops the obsolete legacy index `dd_hooks_config_stage_pos_sort_idx` and the
 *   superseded `stage` and `position` columns from `domain_deployment_hooks`.
 */
return new class extends Migration
{
    private const TABLE = 'domain_deployment_hooks';

    private const LEGACY_INDEX = 'dd_hooks_config_stage_pos_sort_idx';

    private const CANONICAL_INDEX = 'dd_hooks_config_slot_sort_idx';

    private const CANONICAL_SLOTS = [
        'before_clone',
        'after_clone',
        'after_install',
        'after_activate',
        'after_purge',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // 1. Defensive backfill & guard check before tightening constraints.
        DB::table(self::TABLE)
            ->where(function ($query): void {
                $query->whereNull('slot')
                    ->orWhere('slot', '')
                    ->orWhereNotIn('slot', self::CANONICAL_SLOTS);
            })
            ->update(['slot' => 'after_purge']);

        DB::table(self::TABLE)
            ->whereNull('sort_order')
            ->update(['sort_order' => 0]);

        $invalidCount = DB::table(self::TABLE)
            ->where(function ($query): void {
                $query->whereNull('slot')
                    ->orWhereNull('sort_order')
                    ->orWhereNotIn('slot', self::CANONICAL_SLOTS);
            })
            ->count();

        if ($invalidCount > 0) {
            throw new RuntimeException(
                "Cannot finalize domain_deployment_hooks contract: {$invalidCount} row(s) still have invalid slot or sort_order values."
            );
        }

        // 2. Drop obsolete legacy index before dropping legacy columns (required for SQLite/MariaDB/MySQL/PostgreSQL).
        if ($this->hasIndex(self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::LEGACY_INDEX);
            });
        }

        // 3. Drop superseded legacy columns.
        $columnsToDrop = array_values(array_filter(
            ['stage', 'position'],
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columnsToDrop !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columnsToDrop): void {
                $table->dropColumn($columnsToDrop);
            });
        }

        // 4. Tighten canonical constraints and ensure composite index exists.
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('slot', 24)->default('after_purge')->nullable(false)->change();
            $table->unsignedInteger('sort_order')->default(0)->nullable(false)->change();
        });

        if (! $this->hasIndex(self::CANONICAL_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['config_id', 'slot', 'sort_order'], self::CANONICAL_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'stage')) {
                $table->string('stage', 20)->nullable()->after('name');
            }

            if (! Schema::hasColumn(self::TABLE, 'position')) {
                $table->string('position', 10)->nullable()->after('stage');
            }
        });

        if (! $this->hasIndex(self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['config_id', 'stage', 'position', 'sort_order'], self::LEGACY_INDEX);
            });
        }
    }

    private function hasIndex(string $indexName): bool
    {
        $indexes = Schema::getIndexes(self::TABLE);

        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
