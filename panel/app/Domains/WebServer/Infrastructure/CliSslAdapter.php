<?php

namespace App\Domains\WebServer\Infrastructure;

use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliSslAdapter implements SslDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function issue(string $domain, string $docRoot, string $email): array
    {
        return $this->runSsl(['issue', $domain, $docRoot, $email], 'Failed to issue SSL certificate.');
    }

    public function renew(string $domain): array
    {
        return $this->runSsl(['renew', $domain], 'Failed to renew SSL certificate.');
    }

    public function installCustom(string $domain, string $certPath, string $keyPath): array
    {
        return $this->runSsl(['custom', $domain, $certPath, $keyPath], 'Failed to install custom SSL certificate.');
    }

    public function isMockMode(): bool
    {
        return $this->lcCli->isMockMode();
    }

    /**
     * @throws LcCliException
     */
    protected function runSsl(array $args, string $defaultError): array
    {
        $res = $this->lcCli->run('ssl', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? $defaultError, 'ssl', $args, $res);
        }

        return $res['data'] ?? [];
    }
}

