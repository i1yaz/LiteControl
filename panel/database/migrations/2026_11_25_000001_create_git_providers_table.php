<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('provider', 32)->default('github_app');
            $table->string('label', 120);
            $table->string('base_url', 255)->default('https://api.github.com');
            $table->string('external_app_id', 64)->nullable();
            $table->string('external_app_slug', 120)->nullable();
            $table->string('external_account_login', 120)->nullable();
            $table->string('external_account_type', 32)->nullable();
            $table->unsignedBigInteger('installation_id')->nullable()->index();
            $table->string('repository_selection', 32)->nullable();
            $table->text('credentials');
            $table->text('webhook_secret');
            $table->string('configured_webhook_url', 255)->nullable();
            $table->string('status', 32)->default('pending_installation');
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'external_app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_providers');
    }
};
