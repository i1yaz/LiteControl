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

class SwitchDomainSourceModeAction
{
    public function __construct(
        private readonly ProviderDeploymentBindingBridgeInterface $bindingBridge,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{source_mode: string, cleared_manual_credentials: bool}
     */
    public function handle(
        User $actor,
        int $domainId,
        int $domainUserId,
        string $domainName,
        SourceMode $targetMode,
        bool $confirmClearManualCredentials = false,
        ?GitProvider $provider = null,
        ?string $remoteRepoId = null,
        ?string $remoteRepoFullName = null,
        ?string $branch = null,
        bool $autoDeploy = true,
        ?string $ipAddress = null,
    ): array {
        if ($domainUserId !== $actor->id) {
            throw new AuthorizationException('You are not authorized to change source mode for this domain.');
        }

        if ($targetMode === SourceMode::Manual) {
            DB::transaction(function () use ($actor, $domainId, $domainName, $ipAddress): void {
                $this->bindingBridge->switchDomainToManual($domainId);

                $this->auditLogger->log([
                    'user_id' => $actor->id,
                    'action' => 'source_control.switched_to_manual',
                    'command' => "source-control switch-manual {$domainName}",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => [
                        'domain_id' => $domainId,
                        'domain' => $domainName,
                        'source_mode' => SourceMode::Manual->value,
                    ],
                ]);
            });

            Log::info('Switched domain deployment to Manual Git Mode.', [
                'user_id' => $actor->id,
                'domain_id' => $domainId,
            ]);

            return [
                'source_mode' => SourceMode::Manual->value,
                'cleared_manual_credentials' => false,
            ];
        }

        if ($provider !== null) {
            if ($provider->user_id !== $actor->id) {
                throw new AuthorizationException('You may only switch to a Source Control provider owned by your account.');
            }

            if ($provider->status !== GitProviderStatus::Active) {
                throw ValidationException::withMessages([
                    'git_provider_id' => ['Selected Source Control provider is not active.'],
                ]);
            }
        }

        $currentState = $this->bindingBridge->getDomainSourceState($domainId);
        if ($currentState['has_manual_credentials'] && ! $confirmClearManualCredentials) {
            throw ValidationException::withMessages([
                'confirm_clear_credentials' => [
                    'Switching from Manual Git Mode to Connected Provider Mode requires confirming that stored manual PAT and SSH deploy keys will be cleared.',
                ],
            ]);
        }

        $result = DB::transaction(function () use (
            $actor,
            $domainId,
            $domainName,
            $confirmClearManualCredentials,
            $provider,
            $remoteRepoId,
            $remoteRepoFullName,
            $branch,
            $autoDeploy,
            $ipAddress,
        ): array {
            $switchResult = $this->bindingBridge->switchDomainToProviderMode(
                domainId: $domainId,
                clearManualCredentials: $confirmClearManualCredentials,
                gitProviderId: $provider?->id,
                remoteRepoId: $remoteRepoId,
                remoteRepoFullName: $remoteRepoFullName,
                branch: $branch,
                autoDeploy: $autoDeploy,
            );

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'source_control.switched_to_provider',
                'command' => "source-control switch-provider {$domainName}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'domain_id' => $domainId,
                    'domain' => $domainName,
                    'source_mode' => SourceMode::Provider->value,
                    'git_provider_id' => $provider?->id,
                    'remote_repo_id' => $remoteRepoId,
                    'remote_repo_full_name' => $remoteRepoFullName,
                    'manual_credentials_cleared' => $switchResult['cleared_manual_credentials'],
                ],
            ]);

            return $switchResult;
        });

        Log::info('Switched domain deployment to Connected Provider Mode.', [
            'user_id' => $actor->id,
            'domain_id' => $domainId,
            'git_provider_id' => $provider?->id,
            'manual_credentials_cleared' => $result['cleared_manual_credentials'],
        ]);

        return [
            'source_mode' => SourceMode::Provider->value,
            'cleared_manual_credentials' => $result['cleared_manual_credentials'],
        ];
    }
}
