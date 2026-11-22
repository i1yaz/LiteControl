<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\WebmailDriverInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InstallWebmailJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $hostname = '',
    ) {
        $this->afterCommit();
    }

    public function handle(WebmailDriverInterface $webmailDriver): void
    {
        $cacheKey = 'module_install_mail_webmail';
        $errorKey = 'module_install_mail_webmail_error';

        Cache::put($cacheKey, 'provisioning', now()->addMinutes(30));
        Cache::forget($errorKey);

        $targetHostname = $this->hostname !== '' ? $this->hostname : (gethostname() ?: 'localhost');

        try {
            $webmailDriver->install($targetHostname);
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
        Cache::put('module_install_mail_webmail', 'failed', now()->addMinutes(30));
        Cache::put(
            'module_install_mail_webmail_error',
            $e?->getMessage() ?: "Module 'mail_webmail' installation timed out or failed.",
            now()->addMinutes(30)
        );
    }
}
