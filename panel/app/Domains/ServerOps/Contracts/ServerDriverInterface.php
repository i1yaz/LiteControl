<?php

namespace App\Domains\ServerOps\Contracts;

use App\Exceptions\System\LcCliException;

interface ServerDriverInterface
{
    public function isMockMode(): bool;

    public function getStats(): array;

    public function getServices(): array;

    /**
     * @throws LcCliException
     */
    public function executeServiceAction(string $service, string $action): array;

    public function getWebAdminStatus(): array;

    /**
     * @throws LcCliException
     */
    public function enableWebAdmin(?string $allowedIps = null): array;

    /**
     * @throws LcCliException
     */
    public function disableWebAdmin(): array;

    public function getProcesses(): array;

    /**
     * @throws LcCliException
     */
    public function killProcess(int $pid): array;

    public function getTimezone(): string;

    /**
     * @throws LcCliException
     */
    public function setTimezone(string $timezone): array;

    public function getHostname(): string;

    /**
     * @throws LcCliException
     */
    public function setHostname(string $hostname): array;

    public function getLogs(string $type = 'ols-error', int $lines = 100): array;
}

