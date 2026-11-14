<?php

declare(strict_types=1);

namespace App\Domains\WebServer\Listeners;

use App\Domains\Identity\Events\UserCreated;
use App\Domains\WebServer\Actions\CreateDomainAction;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProvisionDefaultDomainListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        protected CreateDomainAction $createDomain
    ) {}

    public function handle(UserCreated $event): void
    {
        if (empty($event->data['domain'])) {
            return;
        }

        try {
            $domainRes = $this->createDomain->handle($event->user, [
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
