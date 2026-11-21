<?php

declare(strict_types=1);

namespace App\Domains\Dns\Contracts;

use App\Exceptions\System\LcCliException;

interface PdnsDaemonDriverInterface
{
    public function isMockMode(): bool;

    /**
     * Inspect OS distro family, PowerDNS config/drop-in paths, systemd state, and port 53 ownership.
     *
     * @return array<string, mixed>
     *
     * @throws LcCliException
     */
    public function getStatus(): array;

    /**
     * Atomically write 99-litecontrol.conf drop-in, ensure include-dir in pdns.conf,
     * validate with pdns_server --config-check, restart pdns, and roll back on failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws LcCliException
     */
    public function applyConfig(array $payload): array;
}
