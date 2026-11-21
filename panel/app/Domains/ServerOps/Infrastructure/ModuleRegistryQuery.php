<?php

declare(strict_types=1);

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;

class ModuleRegistryQuery implements ModuleRegistryQueryInterface
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver
    ) {}

    public function isActive(string $moduleKey): bool
    {
        $status = $this->moduleDriver->getModuleStatus($moduleKey);

        if (($status['installed'] ?? false) === true) {
            return true;
        }

        $state = $status['status'] ?? $status['state'] ?? null;

        return in_array($state, ['active', 'installed', 'running'], true);
    }

    public function activeDriver(string $moduleKey): ?string
    {
        $status = $this->moduleDriver->getModuleStatus($moduleKey);

        $isActive = ($status['installed'] ?? false) === true
            || in_array($status['status'] ?? $status['state'] ?? null, ['active', 'installed', 'running'], true);

        if (! $isActive) {
            return null;
        }

        $driver = $status['driver'] ?? null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }
}
