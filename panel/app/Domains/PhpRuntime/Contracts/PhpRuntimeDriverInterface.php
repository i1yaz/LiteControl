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
    public function listExtensions(PhpVersion $version): array;

    /**
     * @throws LcCliException
     */
    public function toggleExtension(PhpVersion $version, PhpExtensionName $extension, string $action): array;

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
}
