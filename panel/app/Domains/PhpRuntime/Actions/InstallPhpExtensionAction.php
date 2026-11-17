<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Actions;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\Identity\Models\User;

class InstallPhpExtensionAction
{
    public function __construct(
        protected PhpRuntimeDriverInterface $phpCli
    ) {}

    public function handle(string $version, string $extension, ?User $actor = null): array
    {
        $verVo = new PhpVersion($version);
        $extVo = new PhpExtensionName($extension);

        $result = $this->phpCli->installExtensionPackage($verVo, $extVo);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'php.extension.install',
            'target' => "lsphp{$verVo->value}-{$extVo->value}",
            'details' => json_encode(['version' => $verVo->value, 'extension' => $extVo->value]),
        ]);

        return $result;
    }
}
