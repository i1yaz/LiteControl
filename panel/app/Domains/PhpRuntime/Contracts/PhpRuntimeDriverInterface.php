<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Contracts;

use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;

interface PhpRuntimeDriverInterface
{
    /**
     * @throws LcCliException
     */
    public function listVersions(): array;

    /**
     * @throws LcCliException
     */
    public function getIni(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function setIni(PhpVersion $version, string $key, string $value): array;

    /**
     * @throws LcCliException
     */
    public function getRawIni(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function setRawIni(PhpVersion $version, string $content): array;

    /**
     * @throws LcCliException
     */
    public function listExtensions(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function installVersion(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function removeVersion(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function listLoadedModules(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function listAvailablePackages(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function installExtensionPackage(PhpVersion $version, PhpExtensionName $extension): array;

    /**
     * @throws LcCliException
     */
    public function uninstallExtensionPackage(PhpVersion $version, PhpExtensionName $extension): array;
}
