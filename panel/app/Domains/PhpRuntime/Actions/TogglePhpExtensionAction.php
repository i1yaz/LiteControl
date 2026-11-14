<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;

class TogglePhpExtensionAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime
    ) {}

    public function handle(
        PhpVersion $version,
        PhpExtensionName $extension,
        string $action,
        User $actor,
        ?string $ipAddress = null
    ): array {
        $result = $this->phpRuntime->toggleExtension($version, $extension, $action);

        $sub = $action === 'enable' ? 'ext-enable' : 'ext-disable';

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => "php.extension_{$action}",
            'command' => "lc-cli php {$sub} {$version->value} {$extension->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => [
                'version' => $version->value,
                'extension' => $extension->value,
                'action' => $action,
            ],
        ]);

        return $result;
    }
}
