<?php

namespace App\Domains\WebServer\Contracts;

use App\Exceptions\System\LcCliException;

interface VhostDriverInterface
{
    /**
     * @throws LcCliException
     */
    public function create(string $domain, string $username, string $phpVersion = '83', string $docRoot = ''): array;

    /**
     * @throws LcCliException
     */
    public function remove(string $domain, bool $deleteFiles = true): array;

    /**
     * @throws LcCliException
     */
    public function getSize(string $domain): int;

    /**
     * @throws LcCliException
     */
    public function getConf(string $domain): string;

    /**
     * @throws LcCliException
     */
    public function setConf(string $domain, string $confContent): array;

    /**
     * @throws LcCliException
     */
    public function fixPermissions(string $domain): array;

    /**
     * @throws LcCliException
     */
    public function switchPhp(string $domain, string $phpVersion): array;

    /**
     * @throws LcCliException
     */
    public function setDocRoot(string $domain, string $docRoot): array;

    /**
     * @throws LcCliException
     */
    public function suspend(string $domain): array;

    /**
     * @throws LcCliException
     */
    public function unsuspend(string $domain): array;

    /**
     * @throws LcCliException
     */
    public function addAlias(string $parentDomain, string $aliasFqdn): array;

    /**
     * @throws LcCliException
     */
    public function removeAlias(string $parentDomain, string $aliasFqdn): array;

    /**
     * @throws LcCliException
     */
    public function toggleOpenBasedir(string $domain, bool $enabled): array;
}
