<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_deployment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('mode', 20)->default('simple');
            $table->string('preset', 30)->default('laravel');
            $table->string('repo_provider', 30)->nullable();
            $table->string('repo_url', 500)->nullable();
            $table->string('branch', 100)->default('main');
            $table->string('fetch_driver', 30)->default('shallow_git');
            $table->text('deploy_key_public')->nullable();
            $table->text('deploy_key_private_encrypted')->nullable();
            $table->text('api_token_encrypted')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->string('web_subpath', 100)->default('public');
            $table->string('php_version', 10)->default('83');
            $table->unsignedTinyInteger('keep_releases')->default(5);
            $table->json('shared_dirs')->nullable();
            $table->json('shared_files')->nullable();
            $table->boolean('health_check_enabled')->default(true);
            $table->string('health_check_path', 255)->default('/');
            $table->boolean('auto_rollback')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_deployment_configs');
    }
};
