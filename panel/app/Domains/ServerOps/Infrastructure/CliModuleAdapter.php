<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliModuleAdapter implements ModuleDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listModules(): array
    {
        $res = $this->lcCli->run('module', ['list']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    public function getModuleStatus(string $module): array
    {
        $res = $this->lcCli->run('module', ['status', $module]);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return ['installed' => false, 'state' => 'not_installed'];
    }

    public function installModule(string $module): array
    {
        $res = $this->lcCli->run('module', ['install', $module]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to install module $module.", 'module', ['install', $module]);
        }

        return $res['data'] ?? [];
    }

    public function removeModule(string $module): array
    {
        $res = $this->lcCli->run('module', ['remove', $module]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to remove module $module.", 'module', ['remove', $module]);
        }

        return $res['data'] ?? [];
    }
}

