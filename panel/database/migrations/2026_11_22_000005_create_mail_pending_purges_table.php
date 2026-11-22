<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_pending_purges', function (Blueprint $table): void {
            $table->id();
            $table->enum('entity_type', ['domain', 'mailbox']);
            $table->string('domain', 255);
            $table->string('local_part', 64)->nullable();
            $table->string('email', 320)->nullable();
            $table->enum('status', ['pending', 'purging', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'domain']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_pending_purges');
    }
};
