<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Contracts;

interface ProviderDeploymentBindingBridgeInterface
{
    public function countLinkedDomains(int $gitProviderId): int;

    /**
     * @return list<array{domain_id: int, domain: string}>
     */
    public function listLinkedDomains(int $gitProviderId): array;

    /**
     * @param  list<int>  $gitProviderIds
     * @return array<int, list<array{domain_id: int, domain: string}>>
     */
    public function listLinkedDomainsByProviderIds(array $gitProviderIds): array;

    /**
     * Detach all domains bound to this provider and switch them back to manual mode.
     */
    public function detachLinkedDomainsToManual(int $gitProviderId): int;

    /**
     * Inspect a domain's current deployment source state.
     *
     * @return array{
     *     exists: bool,
     *     source_mode: string,
     *     git_provider_id: ?int,
     *     has_manual_credentials: bool,
     *     has_api_token: bool,
     *     has_deploy_key: bool,
     * }
     */
    public function getDomainSourceState(int $domainId): array;

    /**
     * Bind a domain's deployment config to a connected Git provider.
     *
     * @param  array<string, mixed>  $deploymentSettings
     * @return array{cleared_manual_credentials: bool, was_simple_mode: bool}
     */
    public function bindDomainToProvider(
        int $domainId,
        int $gitProviderId,
        string $remoteRepoId,
        string $remoteRepoFullName,
        string $branch,
        bool $autoDeploy,
        bool $clearManualCredentials,
        array $deploymentSettings = [],
    ): array;

    /**
     * Switch a domain's deployment config from provider mode back to manual mode.
     */
    public function switchDomainToManual(int $domainId): void;

    /**
     * Switch a domain's deployment config from manual mode to provider mode, clearing manual credentials.
     *
     * @return array{cleared_manual_credentials: bool}
     */
    public function switchDomainToProviderMode(
        int $domainId,
        bool $clearManualCredentials,
        ?int $gitProviderId = null,
        ?string $remoteRepoId = null,
        ?string $remoteRepoFullName = null,
        ?string $branch = null,
        bool $autoDeploy = true,
    ): array;
}
