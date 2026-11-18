<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('domain_deployments')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('step_name', 120);
            $table->string('status', 20)->default('pending');
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('log_excerpt')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_deployment_steps');
    }
};
