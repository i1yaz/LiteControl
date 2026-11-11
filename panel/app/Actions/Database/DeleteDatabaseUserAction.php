<?php

namespace App\Actions\Database;

use App\Models\AuditLog;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Support\Facades\DB;

class DeleteDatabaseUserAction
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Delete a database user via CLI and database.
     */
    public function handle(DatabaseUser $dbUser, User $actor, ?string $ipAddress = null): void
    {
        $username = $dbUser->db_username;

        $this->databaseCli->deleteUser($username);

        DB::transaction(function () use ($dbUser, $actor, $username, $ipAddress) {
            $dbUser->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db_user.delete',
                'command' => "lc-cli db delete-user {$username}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username],
            ]);
        });
    }
}

