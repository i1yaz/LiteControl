<?php

namespace App\Actions\Database;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteDatabaseAction
{
    protected array $protectedDatabases = [
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'litecontrol',
        'pdns',
    ];

    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

    /**
     * Delete a database and remove its record.
     *
     * @throws InvalidArgumentException
     * @throws LcCliException
     */
    public function handle(Database $database, User $actor, ?string $ipAddress = null): void
    {
        $dbName = $database->db_name;

        if (in_array(strtolower($dbName), $this->protectedDatabases, true)) {
            throw new InvalidArgumentException("Database '{$dbName}' is a protected system database.");
        }

        $this->databaseCli->deleteDatabase($dbName);

        DB::transaction(function () use ($database, $actor, $dbName, $ipAddress) {
            $database->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'db.delete',
                'command' => "lc-cli db delete-db {$dbName}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['db_name' => $dbName],
            ]);
        });
    }
}

