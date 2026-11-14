<?php

namespace App\Domains\ServerOps\Contracts;

use App\Exceptions\System\LcCliException;

interface ModuleDriverInterface
{
    public function listModules(): array;

    public function getModuleStatus(string $module): array;

    /**
     * @throws LcCliException
     */
    public function installModule(string $module): array;

    /**
     * @throws LcCliException
     */
    public function removeModule(string $module): array;
}
