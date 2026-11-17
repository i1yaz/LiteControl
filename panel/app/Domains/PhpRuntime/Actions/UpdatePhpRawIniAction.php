<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Models\AuditLog;

class UpdatePhpRawIniAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime
    ) {}

    public function handle(PhpVersion $version, string $content, User $actor, string $ipAddress): array
    {
        $result = $this->phpRuntime->setRawIni($version, $content);

        AuditLog::log(
            'php.ini.raw_updated',
            'php',
            "lsphp{$version->value}",
            [
                'command' => "lc-cli php ini-raw-set {$version->value}",
                'bytes' => strlen($content),
            ],
            $actor->id,
            $ipAddress
        );

        return $result;
    }
}
