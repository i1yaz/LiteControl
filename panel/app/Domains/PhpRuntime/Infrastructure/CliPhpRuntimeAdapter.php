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
        return $this->runPhp(['list'], 'Failed to list PHP versions.');
    }

    public function getIni(PhpVersion $version): array
    {
        return $this->runPhp(['ini-get', $version->value], "Failed to get PHP INI for version {$version->value}.");
    }

    public function setIni(PhpVersion $version, string $key, string $value): array
    {
        return $this->runPhp(['ini-set', $version->value, "{$key}={$value}"], "Failed to set PHP INI {$key} for version {$version->value}.");
    }

    public function listExtensions(PhpVersion $version): array
    {
        $data = $this->runPhp(['ext-list', $version->value], "Failed to list PHP extensions for version {$version->value}.");

        return $data ?: [
            'installed' => [],
            'available' => [],
        ];
    }

    public function toggleExtension(PhpVersion $version, PhpExtensionName $extension, string $action): array
    {
        if (! in_array($action, ['enable', 'disable'], true)) {
            throw new \InvalidArgumentException("Invalid extension action: {$action}");
        }

        $sub = $action === 'enable' ? 'ext-enable' : 'ext-disable';

        return $this->runPhp([$sub, $version->value, $extension->value], "Failed to {$action} extension {$extension->value} for PHP {$version->value}.");
    }

    public function installVersion(PhpVersion $version): array
    {
        return $this->runPhp(['install', $version->value], "Failed to install PHP {$version->value}.");
    }

    public function removeVersion(PhpVersion $version): array
    {
        return $this->runPhp(['remove', $version->value], "Failed to remove PHP {$version->value}.");
    }

    public function listLoadedModules(PhpVersion $version): array
    {
        return $this->runPhp(['modules', $version->value], "Failed to list loaded PHP modules for version {$version->value}.");
    }

    /**
     * @throws LcCliException
     */
    protected function runPhp(array $args, string $defaultError): array
    {
        $res = $this->lcCli->run('php', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? $defaultError, 'php', $args, $res);
        }

        return (array) ($res['data'] ?? []);
    }
}
