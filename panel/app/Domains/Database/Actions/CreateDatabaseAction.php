<?php

namespace App\Domains\Database\Actions;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\Database;
use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateDatabaseAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver
    ) {}

    /**
     * Create a system database and corresponding model record.
     */
    public function handle(DatabaseName $dbName, User $targetUser, User $actor, ?int $domainId = null, ?string $ipAddress = null): Database
    {
        $this->databaseDriver->createDatabase($dbName);

        return DB::transaction(function () use ($dbName, $targetUser, $actor, $domainId, $ipAddress) {
            $database = Database::create([
                'user_id' => $targetUser->id,
                'domain_id' => $domainId,
                'db_name' => $dbName->value,
                'size_bytes' => 0,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db.create',
                'command' => "lc-cli db create-db {$dbName->value}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_name' => $dbName->value, 'user' => $targetUser->username],
            ]);

            return $database;
        });
    }
}

