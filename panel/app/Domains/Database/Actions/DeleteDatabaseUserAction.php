<?php

namespace App\Domains\Database\Actions;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\DatabaseUser;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

class DeleteDatabaseUserAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Delete a database user via CLI and database.
     */
    public function handle(DatabaseUser $dbUser, User $actor, ?string $ipAddress = null): void
    {
        $username = DatabaseUsername::fromString($dbUser->db_username);

        $this->databaseDriver->deleteUser($username);

        DB::transaction(function () use ($dbUser, $actor, $username, $ipAddress) {
            $dbUser->delete();

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor->id,
                'action' => 'db_user.delete',
                'command' => "lc-cli db delete-user {$username->value}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_username' => $username->value],
            ]);
        });
    }
}
