<?php

namespace App\Domains\WebServer\Infrastructure;

use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliVhostAdapter implements VhostDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function create(string $domain, string $username, string $phpVersion = '83', string $docRoot = ''): array
    {
        $args = ['add', $domain, $username, $phpVersion];
        if (! empty($docRoot)) {
            $args[] = $docRoot;
        }

        return $this->runVhost($args, 'Failed to create virtual host.');
    }

    public function remove(string $domain, bool $deleteFiles = true): array
    {
        $args = ['remove', $domain];
        if ($deleteFiles) {
            $args[] = '--delete-files';
        }

        return $this->runVhost($args, 'Failed to remove virtual host.');
    }

    public function getSize(string $domain): int
    {
        $data = $this->runVhost(['size', $domain], 'Failed to get virtual host size.');

        return (int) ($data['size_bytes'] ?? 0);
    }

    public function getConf(string $domain): string
    {
        $data = $this->runVhost(['conf-get', $domain], 'Failed to get virtual host configuration.');

        return (string) ($data['conf'] ?? '');
    }

    public function setConf(string $domain, string $confContent): array
    {
        $b64Conf = base64_encode($confContent);

        return $this->runVhost(['conf-set', $domain, $b64Conf], 'Failed to save virtual host configuration.');
    }

    public function fixPermissions(string $domain): array
    {
        return $this->runVhost(['fix-permissions', $domain], 'Failed to fix virtual host permissions.');
    }

    public function switchPhp(string $domain, string $phpVersion): array
    {
        return $this->runVhost(['php-switch', $domain, $phpVersion], 'Failed to switch PHP version.');
    }

    public function setDocRoot(string $domain, string $docRoot): array
    {
        return $this->runVhost(['docroot', $domain, $docRoot], 'Failed to update document root.');
    }

    public function suspend(string $domain): array
    {
        return $this->runVhost(['suspend', $domain], 'Failed to suspend domain.');
    }

    public function unsuspend(string $domain): array
    {
        return $this->runVhost(['unsuspend', $domain], 'Failed to unsuspend domain.');
    }

    public function addAlias(string $parentDomain, string $aliasFqdn): array
    {
        return $this->runVhost(['alias-add', $parentDomain, $aliasFqdn], 'Failed to add domain alias.');
    }

    public function removeAlias(string $parentDomain, string $aliasFqdn): array
    {
        return $this->runVhost(['alias-remove', $parentDomain, $aliasFqdn], 'Failed to remove domain alias.');
    }

    public function toggleOpenBasedir(string $domain, bool $enabled): array
    {
        return $this->runVhost(['open-basedir', $domain, $enabled ? 'on' : 'off'], 'Failed to toggle open_basedir.');
    }

    /**
     * @throws LcCliException
     */
    protected function runVhost(array $args, string $defaultError): array
    {
        $res = $this->lcCli->run('vhost', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? $defaultError, 'vhost', $args, $res);
        }

        return $res['data'] ?? [];
    }
}
