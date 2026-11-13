<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Models\AuditLog;
use App\Models\User;

class UpdatePhpIniAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime
    ) {}

    public function handle(PhpVersion $version, array $directives, User $actor, ?string $ipAddress = null): array
    {
        $updated = [];

        foreach ($directives as $key => $val) {
            if ($val !== null) {
                $this->phpRuntime->setIni($version, (string) $key, (string) $val);
                $updated[] = $key;
            }
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'php.ini_update',
            'command' => "lc-cli php ini-set {$version->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version->value, 'updated' => $updated],
        ]);

        return $updated;
    }
}

