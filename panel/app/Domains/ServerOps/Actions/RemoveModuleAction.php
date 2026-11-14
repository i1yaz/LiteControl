<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class RemoveModuleAction
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Remove / uninstall an optional module.
     *
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->moduleDriver->removeModule($module);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'remove_module',
            'command' => "lc-cli module remove {$module}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['module' => $module]),
        ]);

        return $result;
    }
}
