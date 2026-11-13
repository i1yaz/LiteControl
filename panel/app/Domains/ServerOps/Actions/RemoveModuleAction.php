<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;

class RemoveModuleAction
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver
    ) {}

    /**
     * Remove / uninstall an optional module.
     *
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->moduleDriver->removeModule($module);

        AuditLog::create([
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

