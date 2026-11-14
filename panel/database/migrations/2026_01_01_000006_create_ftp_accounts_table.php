<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->unsignedInteger('uid')->default(5001);
            $table->unsignedInteger('gid')->default(5001);
            $table->string('dir');
            $table->unsignedBigInteger('quota_mb')->default(0);
            $table->string('status')->default('active'); // 'active' | 'suspended'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftp_accounts');
    }
};
