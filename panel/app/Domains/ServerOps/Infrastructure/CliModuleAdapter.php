<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\ModuleRegistry;
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

    private function toCliModuleKey(string $module): string
    {
        return ModuleRegistry::normalizeKey($module);
    }

    public function getModuleStatus(string $module): array
    {
        $cliModule = $this->toCliModuleKey($module);
        $res = $this->lcCli->run('module', ['status', $cliModule]);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return ['installed' => false, 'status' => 'not_installed', 'state' => 'not_installed'];
    }

    public function getModuleInstallLog(string $module, int $lines = 100): string
    {
        $cliModule = $this->toCliModuleKey($module);
        if (! preg_match('/^[a-z0-9_-]+$/', $cliModule)) {
            return '';
        }

        $logPath = "/var/log/litecontrol/{$cliModule}-install.log";
        if (! is_file($logPath) || ! is_readable($logPath)) {
            return '';
        }

        $content = @file_get_contents($logPath);
        if ($content === false || $content === '') {
            return '';
        }

        $allLines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];

        return implode("\n", array_slice($allLines, -max(1, $lines)));
    }

    public function installModule(string $module): array
    {
        $cliModule = $this->toCliModuleKey($module);
        $res = $this->lcCli->run('module', ['install', $cliModule]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to install module $module.", 'module', ['install', $cliModule]);
        }

        return $res['data'] ?? [];
    }

    public function removeModule(string $module): array
    {
        $cliModule = $this->toCliModuleKey($module);
        $res = $this->lcCli->run('module', ['remove', $cliModule]);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException($res['message'] ?? "Failed to remove module $module.", 'module', ['remove', $cliModule]);
        }

        return $res['data'] ?? [];
    }
}
