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
}

