<?php

namespace App\Actions\Configuration;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;

class UpdatePhpLimitsAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpCli
    ) {}

    /**
     * Update PHP resource limits for a specific version.
     *
     * @param string $version
     * @param array<string, mixed> $directives Key-value pairs of PHP INI directives
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return void
     * @throws LcCliException
     */
    public function handle(string $version, array $directives, ?User $actor = null, ?string $ipAddress = null): void
    {
        foreach ($directives as $key => $val) {
            $this->phpCli->setIni(new PhpVersion($version), $key, (string) $val);
        }

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'update_php_limits',
            'command' => "lc-cli php ini-set {$version}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(array_merge(['version' => $version], $directives)),
        ]);
    }
}

