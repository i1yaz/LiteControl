<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('release_id', 40)->index();
            $table->string('commit_sha', 64)->nullable();
            $table->string('commit_message', 500)->nullable();
            $table->string('commit_author', 150)->nullable();
            $table->string('trigger_type', 20);
            $table->string('status', 20)->index();
            $table->string('log_path', 500)->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_deployments');
    }
};
