<?php

declare(strict_types=1);

namespace App\Domains\ServerOps\Jobs;

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InstallModuleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $module,
    ) {
        $this->afterCommit();
    }

    public function handle(ModuleDriverInterface $moduleDriver): void
    {
        $cacheKey = "module_install_{$this->module}";
        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));

        try {
            $moduleDriver->installModule($this->module);
            Cache::put($cacheKey, 'active', now()->addMinutes(30));
        } catch (\Throwable $e) {
            Cache::put($cacheKey, 'failed', now()->addMinutes(30));
            throw $e;
        }
    }
}
