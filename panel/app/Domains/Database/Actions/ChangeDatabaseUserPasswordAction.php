<?php

namespace App\Domains\Database\Actions;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\DatabaseUser;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;

class ChangeDatabaseUserPasswordAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Change a database user's password.
     */
    public function handle(DatabaseUser $dbUser, string $newPassword, User $actor, ?string $ipAddress = null): void
    {
        $username = DatabaseUsername::fromString($dbUser->db_username);

        $this->databaseDriver->changePassword($username, $newPassword);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'db_user.password',
            'command' => "lc-cli db password {$username->value} [REDACTED]",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['db_username' => $username->value],
        ]);
    }
}
