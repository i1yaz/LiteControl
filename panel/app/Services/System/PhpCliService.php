<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Services\LcCliService;

class PhpCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Get list of installed/supported PHP versions.
     *
     * @return array
     * @throws LcCliException
     */
    public function listVersions(): array
    {
        $res = $this->lcCli->run('php', ['list']);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to list PHP versions.', 'php', ['list'], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Get PHP INI directives for a specific version.
     *
     * @throws LcCliException
     */
    public function getIni(string $version): array
    {
        $res = $this->lcCli->run('php', ['ini-get', $version]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to get PHP INI for version {$version}.", 'php', ['ini-get', $version], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Set a PHP INI directive for a specific version.
     *
     * @throws LcCliException
     */
    public function setIni(string $version, string $key, string $value): array
    {
        $arg = "{$key}={$value}";
        $res = $this->lcCli->run('php', ['ini-set', $version, $arg]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to set PHP INI {$key} for version {$version}.", 'php', ['ini-set', $version, $arg], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Get PHP extensions list (installed & available) for a specific version.
     *
     * @param string $version
     * @return array
     * @throws LcCliException
     */
    public function listExtensions(string $version): array
    {
        $res = $this->lcCli->run('php', ['ext-list', $version]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to list PHP extensions for version {$version}.", 'php', ['ext-list', $version], $res);
        }

        return (array) ($res['data'] ?? [
            'installed' => [],
            'available' => [],
        ]);
    }

    /**
     * Enable or disable a PHP extension for a specific version.
     *
     * @param string $version
     * @param string $extension
     * @param string $action 'enable' or 'disable'
     * @return array
     * @throws LcCliException
     */
    public function toggleExtension(string $version, string $extension, string $action): array
    {
        $sub = $action === 'enable' ? 'ext-enable' : 'ext-disable';
        $res = $this->lcCli->run('php', [$sub, $version, $extension]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to {$action} extension {$extension} for PHP {$version}.", 'php', [$sub, $version, $extension], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Install a PHP version.
     *
     * @param string $version
     * @return array
     * @throws LcCliException
     */
    public function installVersion(string $version): array
    {
        $res = $this->lcCli->run('php', ['install', $version]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to install PHP {$version}.", 'php', ['install', $version], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Remove a PHP version.
     *
     * @param string $version
     * @return array
     * @throws LcCliException
     */
    public function removeVersion(string $version): array
    {
        $res = $this->lcCli->run('php', ['remove', $version]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to remove PHP {$version}.", 'php', ['remove', $version], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    /**
     * Get loaded PHP modules (php -m).
     *
     * @param string $version
     * @return array
     * @throws LcCliException
     */
    public function listLoadedModules(string $version): array
    {
        $res = $this->lcCli->run('php', ['modules', $version]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to list loaded PHP modules for version {$version}.", 'php', ['modules', $version], $res);
        }

        return (array) ($res['data'] ?? []);
    }
}

