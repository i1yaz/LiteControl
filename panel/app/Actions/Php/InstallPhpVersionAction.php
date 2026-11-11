<?php

namespace App\Actions\Php;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;

class InstallPhpVersionAction
{
    public function __construct(
        protected PhpCliService $phpCli
    ) {}

    /**
     * Install a PHP version.
     *
     * @param string $version
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $version, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->phpCli->installVersion($version);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.install',
            'command' => "lc-cli php install {$version}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version],
        ]);

        return $result;
    }
}

