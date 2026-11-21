<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('domain', 255)->unique();
            $table->enum('ownership_source', ['webserver', 'admin_override', 'dns_txt'])->default('webserver');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('default_quota_mb')->default(1024);
            $table->unsignedInteger('max_accounts')->default(50);
            $table->boolean('dkim_enabled')->default(false);
            $table->boolean('spam_filter_enabled')->default(true);
            $table->decimal('spam_header_score', 4, 1)->default(5.0);
            $table->decimal('spam_reject_score', 4, 1)->default(15.0);
            $table->unsignedInteger('outbound_hourly_limit')->default(100);
            $table->timestamp('tls_sni_synced_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['domain', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_domains');
    }
};
