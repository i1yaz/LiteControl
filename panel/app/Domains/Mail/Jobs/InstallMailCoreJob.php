<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InstallMailCoreJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $hostname = '',
    ) {
        $this->afterCommit();
    }

    public function handle(MailServerDriverInterface $mailDriver): void
    {
        $cacheKey = 'module_install_mail_core';
        $errorKey = 'module_install_mail_core_error';

        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));
        Cache::forget($errorKey);

        $targetHostname = $this->hostname !== '' ? $this->hostname : (gethostname() ?: 'localhost');

        try {
            $mailDriver->installCore($targetHostname);
            if ($targetHostname !== '' && $targetHostname !== 'localhost') {
                Cache::forever('mail_core_hostname', strtolower($targetHostname));
            }
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
        Cache::put('module_install_mail_core', 'failed', now()->addMinutes(30));
        Cache::put(
            'module_install_mail_core_error',
            $e?->getMessage() ?: "Module 'mail_core' installation timed out or failed.",
            now()->addMinutes(30)
        );
    }
}
