<?php

namespace App\Actions\Domain;

use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\System\VhostService;

class FixDomainPermissionsAction
{
    public function __construct(
        protected VhostService $vhostService
    ) {}

    /**
     * Fix file and directory permissions for domain.
     *
     * @return array
     */
    public function handle(Domain $domain, User $actor): array
    {
        $res = $this->vhostService->fixPermissions($domain->domain);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'domain.fix_permissions',
            'command' => "lc-cli vhost fix-permissions {$domain->domain}",
            'status' => ($res['status'] ?? '') === 'success' ? 'success' : 'error',
            'details' => [
                'domain' => $domain->domain,
                'cli_result' => $res,
            ],
        ]);

        return $res;
    }
}
