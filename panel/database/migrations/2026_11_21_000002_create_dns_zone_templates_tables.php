<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zone_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('dns_zone_template_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_zone_template_id')
                ->constrained('dns_zone_templates')
                ->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('type', 16);
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedInteger('priority')->nullable();
            $table->text('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zone_template_records');
        Schema::dropIfExists('dns_zone_templates');
    }
};
