<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Models\User;

class UpdateVhostConfAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver
    ) {}

    /**
     * Update Virtual Host configuration.
     */
    public function handle(Domain $domain, string $conf, User $actor, ?string $ipAddress = null): array
    {
        $res = $this->vhostDriver->setConf($domain->domain, $conf);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'domain.vhost_conf_update',
            'command' => "update vhconf for {$domain->domain}",
            'ip_address' => $ipAddress,
        ]);

        return $res;
    }
}

