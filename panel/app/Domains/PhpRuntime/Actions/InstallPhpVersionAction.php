<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class InstallPhpVersionAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime
    ) {}

    public function handle(PhpVersion $version, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->phpRuntime->installVersion($version);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.install',
            'command' => "lc-cli php install {$version->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version->value],
        ]);

        return $result;
    }
}

