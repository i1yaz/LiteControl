<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table): void {
            $table->string('mail_hostname', 255)->nullable()->after('domain');
            $table->boolean('uses_shared_mail_host')->default(false)->after('mail_hostname');
            $table->index('mail_hostname');
        });
    }

    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table): void {
            $table->dropIndex(['mail_hostname']);
            $table->dropColumn(['mail_hostname', 'uses_shared_mail_host']);
        });
    }
};
