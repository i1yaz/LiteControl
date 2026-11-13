<?php

namespace App\Actions\Domain;

use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\System\VhostService;

class UpdateVhostConfAction
{
    public function __construct(
        protected VhostService $vhostService
    ) {}

    /**
     * Update Virtual Host configuration.
     *
     * @return array
     */
    public function handle(Domain $domain, string $conf, User $actor, ?string $ipAddress = null): array
    {
        $res = $this->vhostService->setConf($domain->domain, $conf);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'domain.vhost_conf_update',
            'command' => "update vhconf for {$domain->domain}",
            'ip_address' => $ipAddress,
        ]);

        return $res;
    }
}
