<?php

namespace App\Actions\Domain;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\System\VhostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteDomainAction
{
    public function __construct(
        protected VhostService $vhostService
    ) {}

    /**
     * Delete a domain from virtual hosts and database.
     */
    public function handle(Domain $domain, User $actor, bool $deleteFiles = true, ?string $ipAddress = null): void
    {
        $domainName = $domain->domain;

        // Call vhost service to remove virtual host
        try {
            $this->vhostService->remove($domainName, $deleteFiles);
        } catch (\Throwable $e) {
            Log::warning("Vhost remove warning/error for {$domainName}: {$e->getMessage()}");
            // Still proceed with cleanup or throw depending on domain requirements
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
                'command' => "lc-cli vhost remove {$domainName}" . ($deleteFiles ? ' --delete-files' : ''),
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => ['domain' => $domainName],
            ]);
        });
    }
}
