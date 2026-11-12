<?php

namespace App\Actions\Php;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;

class UpdatePhpIniAction
{
    public function __construct(
        protected PhpCliService $phpCli
    ) {}

    /**
     * Execute PHP INI directives update.
     *
     * @param string $version
     * @param array $directives
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $version, array $directives, User $actor, ?string $ipAddress = null): array
    {
        $updated = [];

        foreach ($directives as $key => $val) {
            if ($val !== null) {
                $this->phpCli->setIni($version, (string) $key, (string) $val);
                $updated[] = $key;
            }
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.ini_update',
            'command' => "lc-cli php ini-set {$version}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version, 'updated' => $updated],
        ]);

        return $updated;
    }
}

