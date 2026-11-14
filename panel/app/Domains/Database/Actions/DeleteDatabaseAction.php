<?php

namespace App\Domains\Database\Actions;

use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\Database;
use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteDatabaseAction
{
    public function __construct(
        protected DatabaseDriverInterface $databaseDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Delete a database and remove its record.
     *
     * @throws InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(Database $database, User $actor, ?string $ipAddress = null): void
    {
        $dbName = DatabaseName::fromString($database->db_name);

        if ($dbName->isProtected()) {
            throw new InvalidArgumentException("Database '{$dbName->value}' is a protected system database.");
        }

        $this->databaseDriver->deleteDatabase($dbName);

        DB::transaction(function () use ($database, $actor, $dbName, $ipAddress) {
            $database->delete();

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor->id,
                'action' => 'db.delete',
                'command' => "lc-cli db delete-db {$dbName->value}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_name' => $dbName->value],
            ]);
        });
    }
}
