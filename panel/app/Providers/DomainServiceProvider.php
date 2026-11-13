<?php

namespace App\Providers;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Infrastructure\CliBackupAdapter;
use App\Domains\Backup\Listeners\CleanupUserBackupsListener;
use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Infrastructure\CliDatabaseAdapter;
use App\Domains\Database\Listeners\CleanupUserDatabasesListener;
use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Events\UserDeleted;
use App\Domains\Identity\Infrastructure\CliUserAdapter;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Infrastructure\CliSslAdapter;
use App\Domains\WebServer\Infrastructure\CliVhostAdapter;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\Event;
use App\Domains\PanelConfig\Contracts\TuningDriverInterface;
use App\Domains\PanelConfig\Infrastructure\CliTuningAdapter;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\Infrastructure\CliPhpRuntimeAdapter;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Infrastructure\CliFirewallAdapter;
use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\Contracts\LogReaderInterface;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\ServerOps\Infrastructure\CliModuleAdapter;
use App\Domains\ServerOps\Infrastructure\CliServerAdapter;
use App\Domains\ServerOps\Infrastructure\LocalFileManagerAdapter;
use App\Domains\ServerOps\Infrastructure\LocalLogReaderAdapter;
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
        $this->app->bind(UserDriverInterface::class, CliUserAdapter::class);
        $this->app->bind(TuningDriverInterface::class, CliTuningAdapter::class);
        $this->app->bind(PhpRuntimeDriverInterface::class, CliPhpRuntimeAdapter::class);
        $this->app->bind(FirewallDriverInterface::class, CliFirewallAdapter::class);
        $this->app->bind(FileManagerDriverInterface::class, LocalFileManagerAdapter::class);
        $this->app->bind(ServerDriverInterface::class, CliServerAdapter::class);
        $this->app->bind(ModuleDriverInterface::class, CliModuleAdapter::class);
        $this->app->bind(LogReaderInterface::class, LocalLogReaderAdapter::class);
        $this->app->bind(VhostDriverInterface::class, CliVhostAdapter::class);
        $this->app->bind(SslDriverInterface::class, CliSslAdapter::class);
    }

    /**
     * Bootstrap any domain services or event listeners.
     */
    public function boot(): void
    {
        Event::listen(UserDeleted::class, CleanupUserDatabasesListener::class);
        Event::listen(UserDeleted::class, CleanupUserBackupsListener::class);
        Event::listen(UserDeleted::class, function (UserDeleted $event): void {
            Domain::where('user_id', $event->userId)->delete();
        });
    }
}

