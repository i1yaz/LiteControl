<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdns_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 255)->default('127.0.0.1');
            $table->unsignedInteger('port')->default(8081);
            $table->text('api_key')->nullable();
            $table->string('webserver_allow_from', 500)->default('127.0.0.1,::1');
            $table->text('webserver_password')->nullable();
            $table->boolean('primary_enabled')->default(true);
            $table->boolean('secondary_enabled')->default(false);
            $table->boolean('autosecondary_enabled')->default(false);
            $table->string('allow_axfr_ips', 500)->nullable();
            $table->string('also_notify', 500)->nullable();
            $table->json('default_nameservers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdns_settings');
    }
};
