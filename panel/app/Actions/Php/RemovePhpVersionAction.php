<?php

namespace App\Actions\Php;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;

class RemovePhpVersionAction
{
    public function __construct(
        protected PhpCliService $phpCli
    ) {}

    /**
     * Remove a PHP version.
     *
     * @param string $version
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $version, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->phpCli->removeVersion($version);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.remove',
            'command' => "lc-cli php remove {$version}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version],
        ]);

        return $result;
    }
}

