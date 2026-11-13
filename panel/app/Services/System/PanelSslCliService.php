<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class PanelSslCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Issue Let's Encrypt SSL certificate for LiteControl panel host.
     *
     * @param string $domain
     * @return array
     * @throws LcCliException
     */
    public function issuePanelSsl(string $domain): array
    {
        $res = $this->lcCli->run('ssl', ['panel-ssl', 'issue', $domain]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? 'Failed to apply panel SSL certificate.', 'ssl', ['panel-ssl', 'issue', $domain]);
        }

        return $res['data'] ?? [];
    }

    /**
     * Install custom SSL certificate & private key for LiteControl panel.
     *
     * @param string $certificate
     * @param string $privateKey
     * @return array
     * @throws LcCliException
     */
    public function installCustomPanelSsl(string $certificate, string $privateKey): array
    {
        $tempCert = tempnam(sys_get_temp_dir(), 'panel_cert_');
        $tempKey = tempnam(sys_get_temp_dir(), 'panel_key_');
        file_put_contents($tempCert, $certificate);
        file_put_contents($tempKey, $privateKey);

        try {
            $res = $this->lcCli->run('ssl', ['panel-ssl', 'custom', $tempCert, $tempKey]);

            if (($res['status'] ?? '') === 'error') {
                throw new LcCliException($res['message'] ?? 'Failed to apply panel SSL certificate.', 'ssl', ['panel-ssl', 'custom', $tempCert, $tempKey]);
            }

            return $res['data'] ?? [];
        } finally {
            @unlink($tempCert);
            @unlink($tempKey);
        }
    }
}

