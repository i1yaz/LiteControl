<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Listeners;

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Events\DomainOwnershipTransferred;
use App\Domains\SourceControl\Enums\SourceMode;
use Illuminate\Support\Facades\Log;

class DetachGitProviderOnDomainOwnershipTransferredListener
{
    public function __construct(
        protected AuditLoggerInterface $auditLogger,
    ) {}

    public function handle(DomainOwnershipTransferred $event): void
    {
        $config = DomainDeploymentConfig::query()
            ->where('domain_id', $event->domainId)
            ->first();

        if (! $config) {
            return;
        }

        if ($config->git_provider_id === null && $config->source_mode !== SourceMode::Provider && ! $config->auto_deploy) {
            return;
        }

        $previousProviderId = $config->git_provider_id;

        $config->forceFill([
            'git_provider_id' => null,
            'source_mode' => SourceMode::Manual,
            'auto_deploy' => false,
        ])->save();

        Log::warning('Detached git provider from domain deployment config after domain ownership transfer.', [
            'domain_id' => $event->domainId,
            'domain' => $event->domainName,
            'previous_user_id' => $event->previousUserId,
            'new_user_id' => $event->newUserId,
            'previous_git_provider_id' => $previousProviderId,
        ]);

        $this->auditLogger->log([
            'user_id' => $event->actorId ?? $event->newUserId,
            'action' => 'source_control.provider_detached_on_transfer',
            'command' => "domain transfer {$event->domainName}",
            'status' => 'warning',
            'ip_address' => $event->ipAddress,
            'details' => [
                'domain_id' => $event->domainId,
                'domain' => $event->domainName,
                'previous_user_id' => $event->previousUserId,
                'new_user_id' => $event->newUserId,
                'previous_git_provider_id' => $previousProviderId,
            ],
        ]);
    }
}
