<?php

namespace App\Actions\Module;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ModuleCliService;

class InstallModuleAction
{
    public function __construct(
        protected ModuleCliService $moduleCli
    ) {}

    /**
     * Install an optional module.
     *
     * @param string $module
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->moduleCli->installModule($module);

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

