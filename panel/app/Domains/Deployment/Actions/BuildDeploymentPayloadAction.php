<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use Illuminate\Support\Facades\File;

class BuildDeploymentPayloadAction
{
    private const HOOK_SLOTS = [
        'before_clone',
        'after_clone',
        'before_install',
        'install',
        'after_install',
        'before_activate',
        'after_activate',
        'before_purge',
        'after_purge',
    ];

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

        $sshKeyPath = '';
        $privateKey = $config->deploy_key_private_encrypted;
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
            'deployment_id' => $deployment->id,
            'release_id' => $deployment->release_id,
            'linux_user' => $linuxUser,
            'domain' => $domainName,
            'domain_root' => $domainRoot,
            'spec_path' => $payloadPath,
            'web_subpath' => (string) $config->web_subpath,
            'php_binary' => $phpBinary,
            'composer_cmd' => $composerCmd,
            'repo' => [
                'driver' => (string) $config->fetch_driver,
                'url' => (string) $config->repo_url,
                'branch' => (string) $config->branch,
                'ssh_key_path' => $sshKeyPath,
                'tarball_url' => '',
                'auth_token' => (string) ($config->api_token_encrypted ?? ''),
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
     * Group active hooks into the 9 lc-cli hook slots.
     *
     * @return array<string, list<string>>
     */
    private function groupHooks(DomainDeploymentConfig $config): array
    {
        $grouped = array_fill_keys(self::HOOK_SLOTS, []);

        foreach ($config->hooks as $hook) {
            if (! $hook->is_active) {
                continue;
            }

            $slot = $hook->position === 'run'
                ? $hook->stage
                : "{$hook->position}_{$hook->stage}";

            if (array_key_exists($slot, $grouped)) {
                $grouped[$slot][] = $hook->script;
            }
        }

        return $grouped;
    }
}
