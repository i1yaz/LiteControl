<?php

namespace App\Domains\Database\Actions;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\Database;
use App\Domains\Database\Models\DatabaseUser;
use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GrantDatabaseAccessAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver
    ) {}

    /**
     * Grant privileges to a database user on a specific database.
     */
    public function handle(DatabaseUsername $username, Database $database, User $actor, ?string $ipAddress = null): void
    {
        $dbName = DatabaseName::fromString($database->db_name);

        $this->databaseDriver->grantUser($username, $dbName);

        DB::transaction(function () use ($username, $database, $actor, $ipAddress) {
            DatabaseUser::where('db_username', $username->value)->update(['database_id' => $database->id]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db_user.grant',
                'command' => "lc-cli db grant {$username->value} {$database->db_name}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username->value, 'db_name' => $database->db_name],
            ]);
        });
    }
}

