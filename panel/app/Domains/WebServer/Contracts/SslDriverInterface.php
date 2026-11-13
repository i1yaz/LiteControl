<?php

namespace App\Domains\WebServer\Contracts;

use App\Exceptions\System\LcCliException;

interface SslDriverInterface
{
    /**
     * @throws LcCliException
     */
    public function issue(string $domain, string $docRoot, string $email): array;

    /**
     * @throws LcCliException
     */
    public function renew(string $domain): array;

    /**
     * @throws LcCliException
     */
    public function installCustom(string $domain, string $certPath, string $keyPath): array;

    public function isMockMode(): bool;
}

