<?php

namespace App\Providers;

use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Infrastructure\CliBackupAdapter;
use App\Domains\Backup\Listeners\CleanupUserBackupsListener;
use App\Domains\Backup\Models\Backup;
use App\Domains\Backup\Policies\BackupPolicy;
use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Infrastructure\CliDatabaseAdapter;
use App\Domains\Database\Listeners\CleanupUserDatabasesListener;
use App\Domains\Database\Models\Database;
use App\Domains\Database\Policies\DatabasePolicy;
use App\Domains\Deployment\Listeners\DetachGitProviderOnDomainOwnershipTransferredListener;
use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Infrastructure\CliPdnsDaemonAdapter;
use App\Domains\Dns\Infrastructure\PowerDnsClient;
use App\Domains\Dns\Listeners\SyncMailDnsRecordsOnChangedListener;
use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\FileManager\Infrastructure\CliFileManagerAdapter;
use App\Domains\HostingPlans\Models\Package;
use App\Domains\HostingPlans\Policies\PackagePolicy;
use App\Domains\Identity\Contracts\UserDriverInterface;
use App\Domains\Identity\Events\UserCreated;
use App\Domains\Identity\Events\UserDeleted;
use App\Domains\Identity\Infrastructure\CliUserAdapter;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Mail\Contracts\DnsTxtResolverInterface;
use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Contracts\WebmailDriverInterface;
use App\Domains\Mail\Infrastructure\CliMailServerAdapter;
use App\Domains\Mail\Infrastructure\CliRspamdFilterAdapter;
use App\Domains\Mail\Infrastructure\CliSnappyMailAdapter;
use App\Domains\Mail\Infrastructure\PhpDnsTxtResolver;
use App\Domains\Mail\Listeners\ReconcileMailOnBackupRestoredListener;
use App\Domains\Mail\Listeners\SyncMailSniOnSslIssuedListener;
use App\Domains\Mail\Listeners\TeardownUserMailOnUserDeletedListener;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Policies\MailAccountPolicy;
use App\Domains\Mail\Policies\MailAliasPolicy;
use App\Domains\Mail\Policies\MailDomainPolicy;
use App\Domains\PanelConfig\Contracts\TuningDriverInterface;
use App\Domains\PanelConfig\Infrastructure\CliTuningAdapter;
use App\Domains\PanelConfig\Infrastructure\EloquentAuditLogger;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\Infrastructure\CliPhpRuntimeAdapter;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Contracts\PanelSslDriverInterface;
use App\Domains\Security\Infrastructure\CliFirewallAdapter;
use App\Domains\Security\Infrastructure\CliPanelSslAdapter;
use App\Domains\ServerOps\Contracts\LogReaderInterface;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\ServerOps\Infrastructure\CliLogReaderAdapter;
use App\Domains\ServerOps\Infrastructure\CliModuleAdapter;
use App\Domains\ServerOps\Infrastructure\CliServerAdapter;
use App\Domains\ServerOps\Infrastructure\ModuleRegistryQuery;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Contracts\DomainOwnershipCheckerInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\BackupRestored;
use App\Domains\Shared\Events\DomainOwnershipTransferred;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\Events\SslCertificateIssued;
use App\Domains\SourceControl\Listeners\CleanupUserGitProvidersListener;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\Policies\GitProviderPolicy;
use App\Domains\WebServer\Contracts\FtpDriverInterface;
use App\Domains\WebServer\Contracts\SslDriverInterface;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Infrastructure\CliFtpAdapter;
use App\Domains\WebServer\Infrastructure\CliSslAdapter;
use App\Domains\WebServer\Infrastructure\CliVhostAdapter;
use App\Domains\WebServer\Infrastructure\EloquentDomainOwnershipChecker;
use App\Domains\WebServer\Listeners\ProvisionDefaultDomainListener;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Policies\DomainPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register any domain DriverInterface => CliAdapter bindings.
     */
    public function register(): void
    {
        $this->app->bind(AuditLoggerInterface::class, EloquentAuditLogger::class);
        $this->app->bind(DomainOwnershipCheckerInterface::class, EloquentDomainOwnershipChecker::class);
        $this->app->bind(ModuleRegistryQueryInterface::class, ModuleRegistryQuery::class);
        $this->app->bind(BackupDriverInterface::class, CliBackupAdapter::class);
        $this->app->bind(DatabaseDriverInterface::class, CliDatabaseAdapter::class);
        $this->app->bind(PdnsDaemonDriverInterface::class, CliPdnsDaemonAdapter::class);
        $this->app->bind(PowerDnsClientInterface::class, PowerDnsClient::class);
        $this->app->bind(UserDriverInterface::class, CliUserAdapter::class);
        $this->app->bind(TuningDriverInterface::class, CliTuningAdapter::class);
        $this->app->bind(PhpRuntimeDriverInterface::class, CliPhpRuntimeAdapter::class);
        $this->app->bind(FirewallDriverInterface::class, CliFirewallAdapter::class);
        $this->app->bind(PanelSslDriverInterface::class, CliPanelSslAdapter::class);
        $this->app->bind(FileManagerDriverInterface::class, CliFileManagerAdapter::class);
        $this->app->bind(ServerDriverInterface::class, CliServerAdapter::class);
        $this->app->bind(ModuleDriverInterface::class, CliModuleAdapter::class);
        $this->app->bind(LogReaderInterface::class, CliLogReaderAdapter::class);
        $this->app->bind(VhostDriverInterface::class, CliVhostAdapter::class);
        $this->app->bind(SslDriverInterface::class, CliSslAdapter::class);
        $this->app->bind(FtpDriverInterface::class, CliFtpAdapter::class);
        $this->app->bind(MailServerDriverInterface::class, CliMailServerAdapter::class);
        $this->app->bind(DnsTxtResolverInterface::class, PhpDnsTxtResolver::class);
        $this->app->bind(MailFilterDriverInterface::class, function ($app): MailFilterDriverInterface {
            $driver = $app->make(ModuleRegistryQueryInterface::class)->activeDriver('mail_filter') ?? 'rspamd';

            return match ($driver) {
                default => $app->make(CliRspamdFilterAdapter::class),
            };
        });
        $this->app->bind(WebmailDriverInterface::class, function ($app): WebmailDriverInterface {
            $driver = $app->make(ModuleRegistryQueryInterface::class)->activeDriver('mail_webmail') ?? 'snappymail';

            return match ($driver) {
                default => $app->make(CliSnappyMailAdapter::class),
            };
        });
    }

    /**
     * Bootstrap any domain services or event listeners.
     */
    public function boot(): void
    {
        Event::listen(UserCreated::class, ProvisionDefaultDomainListener::class);
        Event::listen(UserDeleted::class, TeardownUserMailOnUserDeletedListener::class);
        Event::listen(UserDeleted::class, CleanupUserDatabasesListener::class);
        Event::listen(UserDeleted::class, CleanupUserBackupsListener::class);
        Event::listen(UserDeleted::class, function (UserDeleted $event): void {
            Domain::where('user_id', $event->userId)->delete();
        });
        Event::listen(UserDeleted::class, CleanupUserGitProvidersListener::class);
        Event::listen(DomainOwnershipTransferred::class, DetachGitProviderOnDomainOwnershipTransferredListener::class);
        Event::listen(BackupRestored::class, ReconcileMailOnBackupRestoredListener::class);
        Event::listen(SslCertificateIssued::class, SyncMailSniOnSslIssuedListener::class);
        Event::listen(MailDnsRecordsChanged::class, SyncMailDnsRecordsOnChangedListener::class);

        Gate::policy(Domain::class, DomainPolicy::class);
        Gate::policy(Database::class, DatabasePolicy::class);
        Gate::policy(Backup::class, BackupPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Package::class, PackagePolicy::class);
        Gate::policy(MailDomain::class, MailDomainPolicy::class);
        Gate::policy(MailAccount::class, MailAccountPolicy::class);
        Gate::policy(MailAlias::class, MailAliasPolicy::class);
        Gate::policy(GitProvider::class, GitProviderPolicy::class);
    }
}
