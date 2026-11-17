<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use InvalidArgumentException;

class RemovePhpVersionAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpRuntime,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    public function handle(PhpVersion $version, User $actor, ?string $ipAddress = null): array
    {
        $dottedVersion = strlen($version->value) === 2
            ? $version->value[0].'.'.$version->value[1]
            : $version->value;

        if (User::where('php_cli_version', $dottedVersion)->exists()) {
            throw new InvalidArgumentException("Cannot remove PHP {$dottedVersion}: one or more users still have it assigned as their PHP CLI version.");
        }

        $result = $this->phpRuntime->removeVersion($version);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
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
