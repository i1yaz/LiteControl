<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteDomainAction
{
    public function __construct(
        protected VhostDriverInterface $vhostDriver
    ) {}

    /**
     * Delete a domain from virtual hosts and database.
     */
    public function handle(Domain $domain, User $actor, bool $deleteFiles = true, ?string $ipAddress = null): void
    {
        $domainName = $domain->domain;

        try {
            $this->vhostDriver->remove($domainName, $deleteFiles);
        } catch (\Throwable $e) {
            Log::warning("Vhost remove warning/error for {$domainName}: {$e->getMessage()}");
        }

        DB::transaction(function () use ($domain, $actor, $domainName, $deleteFiles, $ipAddress) {
            // Promote direct child subdomains cleanly instead of blocking or orphaning
            if ($domain->parent_domain_id !== null) {
                Domain::where('parent_domain_id', $domain->id)->update([
                    'parent_domain_id' => $domain->parent_domain_id,
                ]);
            } else {
                Domain::where('parent_domain_id', $domain->id)->update([
                    'parent_domain_id' => null,
                    'type' => 'primary',
                ]);
            }

            $domain->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'domain.delete',
                'command' => "lc-cli vhost remove {$domainName}".($deleteFiles ? ' --delete-files' : ''),
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['domain' => $domainName],
            ]);
        });
    }
}
