<?php

namespace App\Actions\Database;

use App\Models\AuditLog;
use App\Models\Database;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Support\Facades\DB;

class CreateDatabaseAction
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Create a system database and corresponding model record.
     */
    public function handle(string $dbName, User $targetUser, User $actor, ?int $domainId = null, ?string $ipAddress = null): Database
    {
        $this->databaseCli->createDatabase($dbName);

        return DB::transaction(function () use ($dbName, $targetUser, $actor, $domainId, $ipAddress) {
            $database = Database::create([
                'user_id' => $targetUser->id,
                'domain_id' => $domainId,
                'db_name' => $dbName,
                'size_bytes' => 0,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db.create',
                'command' => "lc-cli db create-db {$dbName}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_name' => $dbName, 'user' => $targetUser->username],
            ]);

            return $database;
        });
    }
}

