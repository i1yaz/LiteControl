<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_deployment_configs', function (Blueprint $table) {
            $table->string('source_mode', 16)->default('manual')->after('mode');
            $table->foreignId('git_provider_id')->nullable()->after('source_mode')->constrained('git_providers')->restrictOnDelete();
            $table->string('remote_repo_id', 64)->nullable()->after('git_provider_id');
            $table->string('remote_repo_full_name', 255)->nullable()->after('remote_repo_id');
            $table->boolean('auto_deploy')->default(false)->after('remote_repo_full_name');
        });
    }

    public function down(): void
    {
        Schema::table('domain_deployment_configs', function (Blueprint $table) {
            $table->dropForeign(['git_provider_id']);
            $table->dropColumn([
                'source_mode',
                'git_provider_id',
                'remote_repo_id',
                'remote_repo_full_name',
                'auto_deploy',
            ]);
        });
    }
};
