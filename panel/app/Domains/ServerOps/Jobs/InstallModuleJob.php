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

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $module,
    ) {
        $this->afterCommit();
    }

    public function handle(ModuleDriverInterface $moduleDriver): void
    {
        $cacheKey = "module_install_{$this->module}";
        $errorKey = "module_install_{$this->module}_error";
        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));
        Cache::forget($errorKey);

        try {
            $moduleDriver->installModule($this->module);
            Cache::put($cacheKey, 'active', now()->addMinutes(30));
            Cache::forget($errorKey);
        } catch (\Throwable $e) {
            Cache::put($cacheKey, 'failed', now()->addMinutes(30));
            Cache::put($errorKey, $e->getMessage(), now()->addMinutes(30));
            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        Cache::put("module_install_{$this->module}", 'failed', now()->addMinutes(30));
        Cache::put(
            "module_install_{$this->module}_error",
            $e?->getMessage() ?: "Module '{$this->module}' installation timed out or failed.",
            now()->addMinutes(30)
        );
    }
}
