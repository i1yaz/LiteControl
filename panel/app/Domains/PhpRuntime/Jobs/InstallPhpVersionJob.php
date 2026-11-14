<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Jobs;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InstallPhpVersionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PhpVersion $version,
    ) {
        $this->afterCommit();
    }

    public function handle(PhpRuntimeDriverInterface $phpRuntime): void
    {
        $cacheKey = "php_install_{$this->version->value}";
        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));

        try {
            $phpRuntime->installVersion($this->version);
            Cache::put($cacheKey, 'active', now()->addMinutes(30));
        } catch (\Throwable $e) {
            Cache::put($cacheKey, 'failed', now()->addMinutes(30));
            throw $e;
        }
    }
}
