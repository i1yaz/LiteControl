<?php

namespace App\Actions\Database;

use App\Models\AuditLog;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\System\DatabaseCliService;

class ChangeDatabaseUserPasswordAction
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Change a database user's password.
     */
    public function handle(DatabaseUser $dbUser, string $newPassword, User $actor, ?string $ipAddress = null): void
    {
        $this->databaseCli->changePassword($dbUser->db_username, $newPassword);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'db_user.password',
            'command' => "lc-cli db password {$dbUser->db_username} [REDACTED]",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['db_username' => $dbUser->db_username],
        ]);
    }
}

