<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('disk_quota_mb')->default(1000); // 0 = unlimited
            $table->unsignedBigInteger('bandwidth_mb')->default(10000); // 0 = unlimited
            $table->unsignedInteger('max_domains')->default(1);
            $table->unsignedInteger('max_subdomains')->default(5);
            $table->unsignedInteger('max_databases')->default(5);
            $table->unsignedInteger('max_ftp_accounts')->default(5);
            $table->unsignedInteger('max_email_accounts')->default(10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
