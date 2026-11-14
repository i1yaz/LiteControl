<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;

class FixDomainPermissionsAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Fix file and directory permissions for domain.
     */
    public function handle(Domain $domain, User $actor): array
    {
        $res = $this->vhostDriver->fixPermissions($domain->domain);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'domain.fix_permissions',
            'command' => "lc-cli vhost fix-permissions {$domain->domain}",
            'status' => 'success',
            'details' => [
                'domain' => $domain->domain,
                'cli_result' => $res,
            ],
        ]);

        return $res;
    }
}
