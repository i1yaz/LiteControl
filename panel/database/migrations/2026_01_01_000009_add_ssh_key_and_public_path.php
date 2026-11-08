<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('ssh_public_key')->nullable()->after('theme');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->string('public_path')->nullable()->after('document_root');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ssh_public_key');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('public_path');
        });
    }
};

