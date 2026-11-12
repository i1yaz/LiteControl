<?php

namespace App\Actions\Module;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ModuleCliService;

class RemoveModuleAction
{
    public function __construct(
        protected ModuleCliService $moduleCli
    ) {}

    /**
     * Remove / uninstall an optional module.
     *
     * @param string $module
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->moduleCli->removeModule($module);

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

