<?php

declare(strict_types=1);

namespace App\Domains\Security\Contracts;

interface PanelSslDriverInterface
{
    /**
     * Issue Let's Encrypt SSL certificate for LiteControl panel host.
     */
    public function issuePanelSsl(string $domain): array;

    /**
     * Install custom SSL certificate & private key for LiteControl panel.
     */
    public function installCustomPanelSsl(string $certificate, string $privateKey): array;
}

