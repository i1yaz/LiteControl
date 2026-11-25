<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\ProviderDeploymentBindingBridgeInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BindDomainSourceControlAction
{
    public function __construct(
        private readonly ProviderDeploymentBindingBridgeInterface $bindingBridge,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $deploymentSettings
     * @return array{cleared_manual_credentials: bool, was_simple_mode: bool}
     */
    public function handle(
        User $actor,
        int $domainId,
        int $domainUserId,
        string $domainName,
        GitProvider $provider,
        string $remoteRepoId,
        string $remoteRepoFullName,
        string $branch,
        bool $autoDeploy = true,
        bool $confirmClearManualCredentials = false,
        array $deploymentSettings = [],
        ?string $ipAddress = null,
    ): array {
        if (! $actor->isAdmin() && ($provider->user_id !== $actor->id || $domainUserId !== $provider->user_id)) {
            throw new AuthorizationException('You may only bind a domain to a Source Control provider that belongs to the same account.');
        }

        if ($actor->isAdmin() && $domainUserId !== $provider->user_id && ! $provider->user?->isAdmin()) {
            throw new AuthorizationException('You may only bind a domain to a Source Control provider owned by the domain owner or an administrator.');
        }

        if ($provider->status !== GitProviderStatus::Active) {
            throw ValidationException::withMessages([
                'git_provider_id' => ['Selected Source Control provider is not active. Complete installation or resolve its status first.'],
            ]);
        }

        $currentState = $this->bindingBridge->getDomainSourceState($domainId);
        $switchingFromManual = $currentState['source_mode'] === SourceMode::Manual->value;

        if ($switchingFromManual && $currentState['has_manual_credentials'] && ! $confirmClearManualCredentials) {
            throw ValidationException::withMessages([
                'confirm_clear_credentials' => [
                    'Switching from Manual Git Mode to a Connected Provider will clear stored manual PAT and SSH deploy keys. Please confirm before continuing.',
                ],
            ]);
        }

        $result = DB::transaction(function () use (
            $actor,
            $domainId,
            $domainName,
            $provider,
            $remoteRepoId,
            $remoteRepoFullName,
            $branch,
            $autoDeploy,
            $switchingFromManual,
            $confirmClearManualCredentials,
            $deploymentSettings,
            $ipAddress,
        ): array {
            $bindResult = $this->bindingBridge->bindDomainToProvider(
                domainId: $domainId,
                gitProviderId: $provider->id,
                remoteRepoId: $remoteRepoId,
                remoteRepoFullName: $remoteRepoFullName,
                branch: $branch,
                autoDeploy: $autoDeploy,
                clearManualCredentials: $switchingFromManual && $confirmClearManualCredentials,
                deploymentSettings: $deploymentSettings,
            );

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => $bindResult['cleared_manual_credentials']
                    ? 'source_control.switched_to_provider'
                    : 'source_control.domain_bound',
                'command' => "source-control bind {$domainName} {$remoteRepoFullName}@{$branch}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'domain_id' => $domainId,
                    'domain' => $domainName,
                    'git_provider_id' => $provider->id,
                    'remote_repo_id' => $remoteRepoId,
                    'remote_repo_full_name' => $remoteRepoFullName,
                    'branch' => $branch,
                    'auto_deploy' => $autoDeploy,
                    'manual_credentials_cleared' => $bindResult['cleared_manual_credentials'],
                ],
            ]);

            return $bindResult;
        });

        Log::info('Bound domain deployment to Source Control provider.', [
            'user_id' => $actor->id,
            'domain_id' => $domainId,
            'git_provider_id' => $provider->id,
            'remote_repo_id' => $remoteRepoId,
            'remote_repo_full_name' => $remoteRepoFullName,
            'branch' => $branch,
            'auto_deploy' => $autoDeploy,
            'manual_credentials_cleared' => $result['cleared_manual_credentials'],
        ]);

        return $result;
    }
}
