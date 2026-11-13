<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Infrastructure;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliPhpRuntimeAdapter implements PhpRuntimeDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listVersions(): array
    {
        $res = $this->lcCli->run('php', ['list']);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to list PHP versions.', 'php', ['list'], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function getIni(PhpVersion $version): array
    {
        $res = $this->lcCli->run('php', ['ini-get', $version->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to get PHP INI for version {$version->value}.", 'php', ['ini-get', $version->value], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function setIni(PhpVersion $version, string $key, string $value): array
    {
        $arg = "{$key}={$value}";
        $res = $this->lcCli->run('php', ['ini-set', $version->value, $arg]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to set PHP INI {$key} for version {$version->value}.", 'php', ['ini-set', $version->value, $arg], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function listExtensions(PhpVersion $version): array
    {
        $res = $this->lcCli->run('php', ['ext-list', $version->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to list PHP extensions for version {$version->value}.", 'php', ['ext-list', $version->value], $res);
        }

        return (array) ($res['data'] ?? [
            'installed' => [],
            'available' => [],
        ]);
    }

    public function toggleExtension(PhpVersion $version, PhpExtensionName $extension, string $action): array
    {
        $sub = $action === 'enable' ? 'ext-enable' : 'ext-disable';
        $res = $this->lcCli->run('php', [$sub, $version->value, $extension->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to {$action} extension {$extension->value} for PHP {$version->value}.", 'php', [$sub, $version->value, $extension->value], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function installVersion(PhpVersion $version): array
    {
        $res = $this->lcCli->run('php', ['install', $version->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to install PHP {$version->value}.", 'php', ['install', $version->value], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function removeVersion(PhpVersion $version): array
    {
        $res = $this->lcCli->run('php', ['remove', $version->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to remove PHP {$version->value}.", 'php', ['remove', $version->value], $res);
        }

        return (array) ($res['data'] ?? []);
    }

    public function listLoadedModules(PhpVersion $version): array
    {
        $res = $this->lcCli->run('php', ['modules', $version->value]);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? "Failed to list loaded PHP modules for version {$version->value}.", 'php', ['modules', $version->value], $res);
        }

        return (array) ($res['data'] ?? []);
    }
}

