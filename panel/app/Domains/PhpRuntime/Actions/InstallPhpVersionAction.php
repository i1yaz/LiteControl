<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\Jobs\InstallPhpVersionJob;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Cache;

class InstallPhpVersionAction
{
    public function __construct(
        protected ?PhpRuntimeDriverInterface $phpRuntime = null,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    public function handle(PhpVersion $version, User $actor, ?string $ipAddress = null): array
    {
        Cache::put("php_install_{$version->value}", 'pending', now()->addMinutes(30));

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'php.install',
            'command' => "lc-cli php install {$version->value}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['version' => $version->value],
        ]);

        InstallPhpVersionJob::dispatch($version);

        return ['status' => 'queued', 'version' => $version->value];
    }
}
