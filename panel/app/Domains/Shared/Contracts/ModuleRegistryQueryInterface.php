<?php

declare(strict_types=1);

namespace App\Domains\Shared\Contracts;

interface ModuleRegistryQueryInterface
{
    public function isActive(string $moduleKey): bool;

    public function activeDriver(string $moduleKey): ?string;
}
