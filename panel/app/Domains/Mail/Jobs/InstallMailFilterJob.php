<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InstallMailFilterJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $moduleKey = 'mail_filter',
        public readonly bool $withAntivirus = false,
    ) {
        $this->afterCommit();
    }

    public function handle(MailFilterDriverInterface $filterDriver): void
    {
        $cacheKey = "module_install_{$this->moduleKey}";
        $errorKey = "module_install_{$this->moduleKey}_error";

        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));
        Cache::forget($errorKey);

        try {
            $filterDriver->install($this->withAntivirus);
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
        Cache::put("module_install_{$this->moduleKey}", 'failed', now()->addMinutes(30));
        Cache::put(
            "module_install_{$this->moduleKey}_error",
            $e?->getMessage() ?: "Module '{$this->moduleKey}' installation timed out or failed.",
            now()->addMinutes(30)
        );
    }
}
