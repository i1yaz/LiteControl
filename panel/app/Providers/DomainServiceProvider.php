<?php

namespace App\Providers;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Infrastructure\CliBackupAdapter;
use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Infrastructure\CliDatabaseAdapter;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\Infrastructure\CliPhpRuntimeAdapter;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Infrastructure\CliFirewallAdapter;
use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\Infrastructure\LocalFileManagerAdapter;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register any domain DriverInterface => CliAdapter bindings.
     */
    public function register(): void
    {
        $this->app->bind(BackupDriverInterface::class, CliBackupAdapter::class);
        $this->app->bind(DatabaseDriverInterface::class, CliDatabaseAdapter::class);
        $this->app->bind(PhpRuntimeDriverInterface::class, CliPhpRuntimeAdapter::class);
        $this->app->bind(FirewallDriverInterface::class, CliFirewallAdapter::class);
        $this->app->bind(FileManagerDriverInterface::class, LocalFileManagerAdapter::class);
    }

    /**
     * Bootstrap any domain services or event listeners.
     */
    public function boot(): void
    {
        //
    }
}

