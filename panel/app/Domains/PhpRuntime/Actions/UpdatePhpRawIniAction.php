<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\Shared\Contracts\AuditLoggerInterface;

class UpdatePhpRawIniAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime,
        protected AuditLoggerInterface $auditLogger
    ) {}

    public function handle(PhpVersion $version, string $content, User $actor, string $ipAddress): array
    {
        $result = $this->phpRuntime->setRawIni($version, $content);

        $this->auditLogger->log([
            'user_id' => $actor->id,
            'action' => 'php.ini_raw_update',
            'command' => "lc-cli php ini-raw-set {$version->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => [
                'version' => $version->value,
                'bytes' => strlen($content),
            ],
        ]);

        return $result;
    }
}
