<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Services\LcCliService;

class VhostService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Create a virtual host via lc-cli.
     *
     * @throws LcCliException
     */
    public function create(string $domain, string $username, string $phpVersion = '83', string $docRoot = ''): array
    {
        $args = ['add', $domain, $username, $phpVersion];
        if (!empty($docRoot)) {
            $args[] = $docRoot;
        }

        $res = $this->lcCli->run('vhost', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to create virtual host.', 'vhost', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Remove a virtual host via lc-cli.
     *
     * @throws LcCliException
     */
    public function remove(string $domain, bool $deleteFiles = true): array
    {
        $args = ['remove', $domain];
        if ($deleteFiles) {
            $args[] = '--delete-files';
        }

        $res = $this->lcCli->run('vhost', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to remove virtual host.', 'vhost', $args, $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Get the virtual host disk size in bytes.
     *
     * @throws LcCliException
     */
    public function getSize(string $domain): int
    {
        $res = $this->lcCli->run('vhost', ['size', $domain]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to get virtual host size.', 'vhost', ['size', $domain], $res);
        }

        return (int) ($res['data']['size_bytes'] ?? 0);
    }

    /**
     * Get the virtual host configuration file content.
     *
     * @throws LcCliException
     */
    public function getConf(string $domain): string
    {
        $res = $this->lcCli->run('vhost', ['conf-get', $domain]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to get virtual host configuration.', 'vhost', ['conf-get', $domain], $res);
        }

        return (string) ($res['data']['conf'] ?? '');
    }

    /**
     * Update the virtual host configuration file content.
     *
     * @throws LcCliException
     */
    public function setConf(string $domain, string $confContent): array
    {
        $b64Conf = base64_encode($confContent);
        $res = $this->lcCli->run('vhost', ['conf-set', $domain, $b64Conf]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to save virtual host configuration.', 'vhost', ['conf-set', $domain], $res);
        }

        return $res['data'] ?? [];
    }

    /**
     * Fix permissions for a virtual host directory structure.
     *
     * @throws LcCliException
     */
    public function fixPermissions(string $domain): array
    {
        $res = $this->lcCli->run('vhost', ['fix-permissions', $domain]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to fix virtual host permissions.', 'vhost', ['fix-permissions', $domain], $res);
        }

        return $res['data'] ?? [];
    }
}

