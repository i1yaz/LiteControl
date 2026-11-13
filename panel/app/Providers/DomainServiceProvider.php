<?php

namespace App\Providers;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Infrastructure\CliBackupAdapter;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\Infrastructure\CliPhpRuntimeAdapter;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register any domain DriverInterface => CliAdapter bindings.
     */
    public function register(): void
    {
        $this->app->bind(BackupDriverInterface::class, CliBackupAdapter::class);
        $this->app->bind(PhpRuntimeDriverInterface::class, CliPhpRuntimeAdapter::class);
    }

    /**
     * Bootstrap any domain services or event listeners.
     */
    public function boot(): void
    {
        //
    }
}

