<?php

namespace App\Actions\Database;

use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Support\Facades\DB;

class CreateDatabaseUserAction
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Create a database user, assign to database optionally, and record in DB.
     */
    public function handle(
        string $username,
        string $password,
        User $targetUser,
        User $actor,
        ?int $databaseId = null,
        ?string $ipAddress = null
    ): DatabaseUser {
        $this->databaseCli->createUser($username, $password);

        if ($databaseId) {
            $database = Database::findOrFail($databaseId);
            $this->databaseCli->grantUser($username, $database->db_name);
        }

        return DB::transaction(function () use ($username, $targetUser, $actor, $databaseId, $ipAddress) {
            $dbUser = DatabaseUser::create([
                'user_id' => $targetUser->id,
                'database_id' => $databaseId,
                'db_username' => $username,
                'host' => 'localhost',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db_user.create',
                'command' => "lc-cli db create-user {$username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username],
            ]);

            return $dbUser;
        });
    }
}

