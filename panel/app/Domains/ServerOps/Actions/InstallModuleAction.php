<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;

class InstallModuleAction
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver
    ) {}

    /**
     * Install an optional module.
     *
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->moduleDriver->installModule($module);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'install_module',
            'command' => "lc-cli module install {$module}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['module' => $module]),
        ]);

        return $result;
    }
}

