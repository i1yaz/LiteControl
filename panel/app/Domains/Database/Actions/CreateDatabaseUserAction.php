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

class CreateDatabaseUserAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver
    ) {}

    /**
     * Create a database user, assign to database optionally, and record in DB.
     */
    public function handle(
        DatabaseUsername $username,
        string $password,
        User $targetUser,
        User $actor,
        ?int $databaseId = null,
        ?string $ipAddress = null
    ): DatabaseUser {
        $this->databaseDriver->createUser($username, $password);

        if ($databaseId) {
            $database = Database::findOrFail($databaseId);
            $this->databaseDriver->grantUser($username, DatabaseName::fromString($database->db_name));
        }

        return DB::transaction(function () use ($username, $targetUser, $actor, $databaseId, $ipAddress) {
            $dbUser = DatabaseUser::create([
                'user_id' => $targetUser->id,
                'database_id' => $databaseId,
                'db_username' => $username->value,
                'host' => 'localhost',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db_user.create',
                'command' => "lc-cli db create-user {$username->value}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username->value],
            ]);

            return $dbUser;
        });
    }
}

