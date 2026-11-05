<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('type')->default('manual'); // 'manual' | 'scheduled'
            $table->string('status')->default('completed'); // 'completed' | 'failed' | 'in_progress'
            $table->timestamps();
        });

        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('frequency')->default('daily'); // 'hourly' | 'daily' | 'weekly' | 'monthly'
            $table->unsignedInteger('retention_count')->default(3);
            $table->string('destination')->default('local'); // 'local' | 'sftp' | 's3'
            $table->json('config_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
        Schema::dropIfExists('backups');
    }
};

