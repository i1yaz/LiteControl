<?php

declare(strict_types=1);

namespace App\Domains\WebServer\Listeners;

use App\Domains\Identity\Events\UserCreated;
use App\Domains\WebServer\Services\DomainService;

class ProvisionDefaultDomainListener
{
    public function __construct(
        protected DomainService $domainService
    ) {}

    public function handle(UserCreated $event): void
    {
        if (empty($event->data['domain'])) {
            return;
        }

        try {
            $domainRes = $this->domainService->createDomain($event->user, [
                'domain' => $event->data['domain'],
                'php_version' => $event->data['php_version'] ?? '83',
                'public_path' => $event->data['public_path'] ?? null,
                'ssl_enabled' => $event->data['ssl_enabled'] ?? true,
                'type' => 'primary',
            ], $event->actor);

            $event->domainCreated = true;
            $event->warning = $domainRes['warning'] ?? null;
        } catch (\Throwable $e) {
            $event->warning = 'User created, but domain provisioning encountered an error: '.$e->getMessage();
        }
    }
}
