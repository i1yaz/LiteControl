<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_deployment_configs', function (Blueprint $table): void {
            $table->text('install_script')->nullable()->after('shared_files');
        });
    }

    public function down(): void
    {
        Schema::table('domain_deployment_configs', function (Blueprint $table): void {
            $table->dropColumn('install_script');
        });
    }
};
