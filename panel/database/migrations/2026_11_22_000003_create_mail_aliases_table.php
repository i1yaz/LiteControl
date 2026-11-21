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
        $isCatchAllExpr = $driver === 'sqlite'
            ? "substr(source_email, 1, 1) = '@'"
            : "LEFT(source_email, 1) = '@'";

        Schema::create('mail_aliases', function (Blueprint $table) use ($isCatchAllExpr): void {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained('mail_domains')->restrictOnDelete();
            $table->string('source_email', 320);
            $table->string('destination_email', 320);
            $table->boolean('is_catch_all')->storedAs($isCatchAllExpr);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['source_email', 'destination_email']);
            $table->index(['source_email', 'active']);
            $table->index('destination_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
    }
};
