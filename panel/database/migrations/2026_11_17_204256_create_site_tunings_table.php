<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_tunings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('domains')->cascadeOnDelete();

            // Layer 1: LSAPI Worker Pool Limits
            $table->unsignedSmallInteger('max_conns')->default(10);
            $table->unsignedSmallInteger('init_timeout')->default(60);
            $table->boolean('persist_conn')->default(true);
            $table->unsignedSmallInteger('pc_keep_alive_timeout')->default(5);
            $table->unsignedInteger('mem_soft_limit_mb')->default(512);
            $table->unsignedInteger('mem_hard_limit_mb')->default(1024);
            $table->unsignedSmallInteger('proc_soft_limit')->default(100);
            $table->unsignedSmallInteger('proc_hard_limit')->default(200);

            // Layer 2: PHP Runtime Directives
            $table->unsignedInteger('php_memory_limit_mb')->default(256);
            $table->unsignedSmallInteger('php_max_execution_time')->default(60);
            $table->unsignedSmallInteger('php_max_input_time')->default(60);
            $table->unsignedInteger('php_upload_max_filesize_mb')->default(64);
            $table->unsignedInteger('php_post_max_size_mb')->default(64);
            $table->unsignedInteger('php_max_input_vars')->default(3000);
            $table->boolean('php_display_errors')->default(false);

            // Lifecycle & Audit
            $table->string('sync_status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tunings');
    }
};
