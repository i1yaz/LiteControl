<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Contracts\ProviderDeploymentBindingBridgeInterface;
use App\Domains\SourceControl\Models\GitProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DisconnectGitProviderAction
{
    public function __construct(
        private readonly ProviderDeploymentBindingBridgeInterface $bindingBridge,
        private readonly GitProviderDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{detached_domains_count: int}
     */
    public function handle(
        User $actor,
        GitProvider $provider,
        bool $switchLinkedToManual = false,
        ?string $ipAddress = null,
    ): array {
        if ($provider->user_id !== $actor->id) {
            throw new AuthorizationException('You are not authorized to disconnect this Source Control provider.');
        }

        $linkedCount = $this->bindingBridge->countLinkedDomains($provider->id);

        if ($linkedCount > 0 && ! $switchLinkedToManual) {
            throw ValidationException::withMessages([
                'provider' => [
                    "Cannot disconnect [{$provider->label}] while {$linkedCount} domain(s) remain linked to it. Switch those domains to Manual Git Mode first or confirm switching them during disconnect.",
                ],
            ]);
        }

        $providerId = $provider->id;
        $providerUuid = $provider->uuid;
        $providerLabel = $provider->label;
        $externalAppSlug = $provider->external_app_slug;

        $detachedCount = DB::transaction(function () use (
            $actor,
            $provider,
            $providerId,
            $providerUuid,
            $providerLabel,
            $externalAppSlug,
            $linkedCount,
            $switchLinkedToManual,
            $ipAddress,
        ): int {
            $detached = 0;

            if ($linkedCount > 0 && $switchLinkedToManual) {
                $detached = $this->bindingBridge->detachLinkedDomainsToManual($providerId);
            }

            $this->driver->bustRepositoriesCache($provider);
            $provider->delete();

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'source_control.provider_disconnected',
                'command' => "source-control disconnect {$providerUuid}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'git_provider_id' => $providerId,
                    'git_provider_uuid' => $providerUuid,
                    'label' => $providerLabel,
                    'external_app_slug' => $externalAppSlug,
                    'detached_domains_count' => $detached,
                ],
            ]);

            return $detached;
        });

        Log::info('Disconnected GitProvider from user account.', [
            'user_id' => $actor->id,
            'git_provider_id' => $providerId,
            'git_provider_uuid' => $providerUuid,
            'detached_domains_count' => $detachedCount,
        ]);

        return [
            'detached_domains_count' => $detachedCount,
        ];
    }
}
