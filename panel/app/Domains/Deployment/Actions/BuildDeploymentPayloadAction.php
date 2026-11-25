<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use App\Domains\SourceControl\Enums\SourceMode;
use Illuminate\Support\Facades\File;

class BuildDeploymentPayloadAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService = new DeploymentPipelineService,
    ) {}

    /**
     * Assemble the JSON deployment spec for lc-cli and write it (plus any SSH deploy key)
     * to the domain's .deploy directory.
     *
     * @return array<string, mixed>
     */
    public function execute(
        DomainDeploymentConfig $config,
        DomainDeployment $deployment,
        ?string $baseHomePath = null
    ): array {
        $config->loadMissing(['domain.user', 'hooks']);

        $domain = $config->domain;
        $linuxUser = $domain->user->username ?? $domain->user->name ?? 'www-data';
        $domainName = $domain->domain;
        $homeBase = rtrim($baseHomePath ?? '/home', '/');
        $domainRoot = "{$homeBase}/{$linuxUser}/{$domainName}";
        // Stage payloads and keys inside Laravel storage in production because the panel user
        // cannot write directly into tenant-owned /home/{user}/{domain}/.deploy (0750).
        // lc-cli (running as root) copies the key to the tenant's .deploy/keys/ with 0600 ownership.
        $deployDir = $baseHomePath !== null
            ? "{$domainRoot}/.deploy"
            : storage_path("app/deployments/domain_{$domain->id}");

        $isProviderMode = $config->source_mode === SourceMode::Provider;

        $sshKeyPath = '';
        $privateKey = $isProviderMode ? null : $config->deploy_key_private_encrypted;
        if (is_string($privateKey) && $privateKey !== '') {
            $keysDir = "{$deployDir}/keys";
            File::ensureDirectoryExists($keysDir, 0700);
            $sshKeyPath = "{$keysDir}/deploy_key";
            File::put($sshKeyPath, rtrim($privateKey)."\n");
            @chmod($sshKeyPath, 0600);
        }

        $phpVersion = preg_replace('/[^0-9]/', '', (string) $config->php_version) ?: '83';
        $phpBinary = "/usr/local/lsws/lsphp{$phpVersion}/bin/php";
        $composerCmd = "{$phpBinary} /usr/local/bin/composer";

        $payloadsDir = "{$deployDir}/payloads";
        File::ensureDirectoryExists($payloadsDir, 0750);
        $payloadPath = "{$payloadsDir}/deploy_{$deployment->release_id}.json";

        $payload = [
            'site_id' => (int) $domain->id,
            'deployment_id' => $deployment->id,
            'release_id' => $deployment->release_id,
            'linux_user' => $linuxUser,
            'domain' => $domainName,
            'domain_root' => $domainRoot,
            'spec_path' => $payloadPath,
            'web_subpath' => (string) $config->web_subpath,
            'php_binary' => $phpBinary,
            'composer_cmd' => $composerCmd,
            'install_script' => $this->pipelineService->normalizeScript($config->install_script),
            'hook_timeout_seconds' => max(1, (int) config('litecontrol.deployment_hook_timeout_seconds', 300)),
            'repo' => [
                'driver' => (string) $config->fetch_driver,
                'url' => (string) $config->repo_url,
                'branch' => (string) $config->branch,
                'ssh_key_path' => $sshKeyPath,
                'tarball_url' => $isProviderMode ? '' : $this->resolveTarballUrl($config),
                'auth_token' => $isProviderMode ? '' : (string) ($config->api_token_encrypted ?? ''),
            ],
            'shared_dirs' => array_values($config->shared_dirs ?? []),
            'shared_files' => array_values($config->shared_files ?? []),
            'keep_releases' => (int) $config->keep_releases,
            'health_check' => [
                'enabled' => (bool) $config->health_check_enabled,
                'path' => (string) $config->health_check_path,
                'timeout_seconds' => 5,
                'retries' => 2,
                'auto_rollback' => (bool) $config->auto_rollback,
            ],
            'hooks' => $this->groupHooks($config),
        ];

        File::put($payloadPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $payload;
    }

    /**
     * Group active hooks into the 5 canonical lc-cli hook slots, ordered by sort_order ASC, id ASC.
     *
     * @return array<string, list<array{id: int, name: string, script: string}>>
     */
    private function groupHooks(DomainDeploymentConfig $config): array
    {
        $grouped = array_fill_keys(DeploymentHookSlot::values(), []);

        $orderedHooks = $config->hooks->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ]);

        /** @var DomainDeploymentHook $hook */
        foreach ($orderedHooks as $hook) {
            if (! $hook->is_active) {
                continue;
            }

            $slot = $hook->slot instanceof DeploymentHookSlot
                ? $hook->slot->value
                : (string) $hook->slot;

            if (! array_key_exists($slot, $grouped)) {
                continue;
            }

            $script = $this->pipelineService->normalizeScript($hook->script);
            if ($script === null) {
                continue;
            }

            $grouped[$slot][] = [
                'id' => (int) $hook->id,
                'name' => (string) $hook->name,
                'script' => $script,
            ];
        }

        return $grouped;
    }

    /**
     * Resolve a provider archive tarball URL from repo_provider, repo_url, and branch
     * when fetch_driver is set to provider_tarball.
     */
    private function resolveTarballUrl(DomainDeploymentConfig $config): string
    {
        if ($config->fetch_driver !== 'provider_tarball') {
            return '';
        }

        $repoUrl = trim((string) $config->repo_url);
        $branch = trim((string) ($config->branch ?: 'main'));
        $provider = strtolower((string) ($config->repo_provider ?? ''));

        if ($repoUrl === '') {
            return '';
        }

        // Extract host and owner/repo path from SSH (git@host:owner/repo.git) or HTTPS URLs.
        $host = '';
        $repoPath = '';
        if (preg_match('#^[^@]+@([^:]+):(.+?)(?:\.git)?$#', $repoUrl, $matches)) {
            $host = strtolower($matches[1]);
            $repoPath = trim($matches[2], '/');
        } elseif (preg_match('#^https?://([^/]+)/(.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            $host = strtolower($matches[1]);
            $repoPath = trim($matches[2], '/');
        }

        if ($repoPath !== '') {
            if ($provider === 'github' || str_contains($host, 'github.com')) {
                return sprintf('https://api.github.com/repos/%s/tarball/%s', $repoPath, rawurlencode($branch));
            }

            if ($provider === 'gitlab' || str_contains($host, 'gitlab.com')) {
                $baseHost = $host !== '' ? $host : 'gitlab.com';

                return sprintf(
                    'https://%s/api/v4/projects/%s/repository/archive.tar.gz?sha=%s',
                    $baseHost,
                    rawurlencode($repoPath),
                    rawurlencode($branch),
                );
            }

            if ($provider === 'bitbucket' || str_contains($host, 'bitbucket.org')) {
                return sprintf('https://bitbucket.org/%s/get/%s.tar.gz', $repoPath, rawurlencode($branch));
            }
        }

        if (str_starts_with($repoUrl, 'http://') || str_starts_with($repoUrl, 'https://')) {
            return $repoUrl;
        }

        return '';
    }
}
