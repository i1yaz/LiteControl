<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;

class RemovePhpVersionAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime
    ) {}

    public function handle(PhpVersion $version, User $actor, ?string $ipAddress = null): array
    {
        $result = $this->phpRuntime->removeVersion($version);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.remove',
            'command' => "lc-cli php remove {$version->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version->value],
        ]);

        return $result;
    }
}

