<?php

namespace App\Actions\Database;

use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Support\Facades\DB;

class GrantDatabaseAccessAction
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Grant privileges to a database user on a specific database.
     */
    public function handle(string $username, Database $database, User $actor, ?string $ipAddress = null): void
    {
        $this->databaseCli->grantUser($username, $database->db_name);

        DB::transaction(function () use ($username, $database, $actor, $ipAddress) {
            DatabaseUser::where('db_username', $username)->update(['database_id' => $database->id]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db_user.grant',
                'command' => "lc-cli db grant {$username} {$database->db_name}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username, 'db_name' => $database->db_name],
            ]);
        });
    }
}

