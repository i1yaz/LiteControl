<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class ModuleCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * List all optional modules and their status.
     *
     * @return array
     */
    public function listModules(): array
    {
        $res = $this->lcCli->run('module', ['list']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Get module status and installation state.
     *
     * @param string $module
     * @return array
     */
    public function getModuleStatus(string $module): array
    {
        $res = $this->lcCli->run('module', ['status', $module]);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return ['installed' => false, 'state' => 'not_installed'];
    }

    /**
     * Install an optional module.
     *
     * @param string $module
     * @return array
     * @throws LcCliException
     */
    public function installModule(string $module): array
    {
        $res = $this->lcCli->run('module', ['install', $module]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to install module $module.", 'module', ['install', $module]);
        }

        return $res['data'] ?? [];
    }

    /**
     * Remove / uninstall an optional module.
     *
     * @param string $module
     * @return array
     * @throws LcCliException
     */
    public function removeModule(string $module): array
    {
        $res = $this->lcCli->run('module', ['remove', $module]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to remove module $module.", 'module', ['remove', $module]);
        }

        return $res['data'] ?? [];
    }
}

