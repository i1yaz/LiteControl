<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultVersion = '8.3';
        $defaultFile = '/etc/lc-panel/php-cli/default';

        if (is_file($defaultFile) && is_readable($defaultFile)) {
            $raw = trim((string) file_get_contents($defaultFile));
            if (preg_match('/^[5-9]\.[0-9]$/', $raw)) {
                $defaultVersion = $raw;
            }
        }

        Schema::table('users', function (Blueprint $table) use ($defaultVersion) {
            $table->string('php_cli_version', 5)->default($defaultVersion)->index()->after('ssh_public_key');
        });

        DB::table('users')->whereNull('php_cli_version')->update(['php_cli_version' => $defaultVersion]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['php_cli_version']);
            $table->dropColumn('php_cli_version');
        });
    }
};
