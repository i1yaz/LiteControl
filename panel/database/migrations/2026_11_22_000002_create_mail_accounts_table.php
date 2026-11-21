<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained('mail_domains')->restrictOnDelete();
            $table->string('local_part', 64);
            $table->string('email', 320)->unique();
            $table->string('password_hash', 255);
            $table->unsignedInteger('quota_mb')->default(1024);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('allow_imap')->default(true);
            $table->boolean('allow_pop3')->default(true);
            $table->boolean('allow_smtp')->default(true);
            $table->timestamps();

            $table->unique(['mail_domain_id', 'local_part']);
            $table->index(['email', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
