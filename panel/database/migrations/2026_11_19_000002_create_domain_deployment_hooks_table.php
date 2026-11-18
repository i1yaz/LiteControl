<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_deployment_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained('domain_deployment_configs')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('stage', 20);
            $table->string('position', 10);
            $table->text('script');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['config_id', 'stage', 'position', 'sort_order'], 'dd_hooks_config_stage_pos_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_deployment_hooks');
    }
};
