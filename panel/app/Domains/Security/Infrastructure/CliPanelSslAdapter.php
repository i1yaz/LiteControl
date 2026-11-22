<?php

declare(strict_types=1);

namespace App\Domains\Security\Infrastructure;

use App\Domains\Security\Contracts\PanelSslDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliPanelSslAdapter implements PanelSslDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function issuePanelSsl(string $domain): array
    {
        $res = $this->lcCli->run('ssl', ['panel-ssl', 'issue', $domain]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to apply panel SSL certificate.', 'ssl', ['panel-ssl', 'issue', $domain]);
        }

        return $res['data'] ?? [];
    }

    public function installCustomPanelSsl(string $certificate, string $privateKey, ?string $domain = null): array
    {
        $tempCert = tempnam(sys_get_temp_dir(), 'panel_cert_');
        $tempKey = tempnam(sys_get_temp_dir(), 'panel_key_');
        file_put_contents($tempCert, $certificate);
        file_put_contents($tempKey, $privateKey);

        try {
            $args = ['panel-ssl', 'custom', $tempCert, $tempKey];
            if ($domain !== null && $domain !== '') {
                $args[] = $domain;
            }

            $res = $this->lcCli->run('ssl', $args);

            if (($res['status'] ?? '') === 'error') {
                throw new LcCliException($res['message'] ?? 'Failed to apply panel SSL certificate.', 'ssl', $args);
            }

            return $res['data'] ?? [];
        } finally {
            @unlink($tempCert);
            @unlink($tempKey);
        }
    }
}
