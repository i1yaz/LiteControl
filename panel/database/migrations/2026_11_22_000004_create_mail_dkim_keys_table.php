<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $activeSlotExpr = $driver === 'sqlite'
            ? "CASE WHEN status = 'active' THEN 1 ELSE NULL END"
            : "IF(status = 'active', 1, NULL)";

        Schema::create('mail_dkim_keys', function (Blueprint $table) use ($activeSlotExpr): void {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained('mail_domains')->restrictOnDelete();
            $table->string('selector', 63);
            $table->enum('algorithm', ['rsa2048', 'ed25519'])->default('rsa2048');
            $table->text('public_key_txt')->nullable();
            $table->string('private_key_path', 512)->nullable();
            $table->char('key_sha256', 64)->nullable();
            $table->enum('status', ['pending', 'active', 'rotating', 'retired'])->default('pending');
            $table->unsignedTinyInteger('active_slot')->nullable()->storedAs($activeSlotExpr);
            $table->timestamp('dns_verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('rotated_at')->nullable();

            $table->unique(['mail_domain_id', 'selector']);
            $table->unique(['mail_domain_id', 'algorithm', 'active_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_dkim_keys');
    }
};
