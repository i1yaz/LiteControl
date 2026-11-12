<?php

namespace App\Actions\Php;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PhpCliService;

class TogglePhpExtensionAction
{
    public function __construct(
        protected PhpCliService $phpCli
    ) {}

    /**
     * Enable or disable a PHP extension.
     *
     * @param string $version
     * @param string $extension
     * @param string $action 'enable' or 'disable'
     * @param User $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(string $version, string $extension, string $action, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->phpCli->toggleExtension($version, $extension, $action);

        $sub = $action === 'enable' ? 'ext-enable' : 'ext-disable';

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => "php.extension_{$action}",
            'command' => "lc-cli php {$sub} {$version} {$extension}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => [
                'version' => $version,
                'extension' => $extension,
                'action' => $action,
            ],
        ]);

        return $result;
    }
}

