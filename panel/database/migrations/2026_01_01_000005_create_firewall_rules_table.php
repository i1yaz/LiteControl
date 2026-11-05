<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('protocol')->default('tcp'); // 'tcp' | 'udp' | 'both'
            $table->string('port');
            $table->string('source_ip')->default('Anywhere');
            $table->string('action')->default('allow'); // 'allow' | 'deny'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};

