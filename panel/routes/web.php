<?php

use App\Http\Controllers\Backup\BackupController;
use App\Http\Controllers\Database\DatabaseController;
use App\Http\Controllers\Deployment\DomainDeploymentController;
use App\Http\Controllers\Deployment\DomainDeploymentEnvController;
use App\Http\Controllers\Deployment\DomainDeploymentHookController;
use App\Http\Controllers\Dns\PdnsAutoprimaryController;
use App\Http\Controllers\Dns\PdnsConfigController;
use App\Http\Controllers\Dns\PdnsRecordController;
use App\Http\Controllers\Dns\PdnsTemplateController;
use App\Http\Controllers\Dns\PdnsZoneController;
use App\Http\Controllers\Dns\PdnsZoneModeController;
use App\Http\Controllers\FileManager\FileManagerController;
use App\Http\Controllers\HostingPlans\PackageController;
use App\Http\Controllers\Identity\AuthController;
use App\Http\Controllers\Identity\UserController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Mail\MailAccountController;
use App\Http\Controllers\Mail\MailDkimController;
use App\Http\Controllers\Mail\MailDomainController;
use App\Http\Controllers\Mail\MailForwarderController;
use App\Http\Controllers\Mail\MailSpamFilterController;
use App\Http\Controllers\PanelConfig\ConfigurationController;
use App\Http\Controllers\PanelConfig\ThemeController;
use App\Http\Controllers\PhpRuntime\PhpController;
use App\Http\Controllers\Security\SecurityController;
use App\Http\Controllers\ServerOps\DashboardController;
use App\Http\Controllers\ServerOps\ModuleController;
use App\Http\Controllers\ServerOps\ServerController;
use App\Http\Controllers\WebServer\DomainController;
use App\Http\Controllers\WebServer\FtpController;
use Illuminate\Support\Facades\Route;

Route::post('/theme/switch', [ThemeController::class, 'switch'])->name('theme.switch');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [AuthController::class, 'verifyTwoFactorChallenge'])->name('two-factor.login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Impersonation revert
    Route::post('/impersonation/revert', [UserController::class, 'revertImpersonation'])->name('impersonation.revert');

    // Domains & Subdomains
    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}/manage', [DomainController::class, 'manage'])->name('domains.manage');
    Route::put('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/suspend', [DomainController::class, 'suspend'])->name('domains.suspend');
    Route::post('/domains/{domain}/unsuspend', [DomainController::class, 'unsuspend'])->name('domains.unsuspend');
    Route::post('/domains/{domain}/php', [DomainController::class, 'switchPhp'])->name('domains.php');
    Route::post('/domains/{domain}/aliases', [DomainController::class, 'storeAlias'])->name('domains.aliases.store');
    Route::delete('/domains/{domain}/aliases/{alias}', [DomainController::class, 'destroyAlias'])->name('domains.aliases.destroy');
    Route::get('/domains/{domain}/logs', [DomainController::class, 'logs'])->name('domains.logs');
    Route::get('/domains/{domain}/vhost-conf', [DomainController::class, 'vhostConf'])->name('domains.vhost-conf');
    Route::post('/domains/{domain}/vhost-conf', [DomainController::class, 'saveVhostConf'])->name('domains.vhost-conf.save');
    Route::get('/domains/{domain}/htaccess', [DomainController::class, 'getHtaccess'])->name('domains.htaccess.get');
    Route::post('/domains/{domain}/htaccess', [DomainController::class, 'saveHtaccess'])->name('domains.htaccess.save');
    Route::middleware('module:ftp')->group(function () {
        Route::post('/domains/{domain}/ftp', [DomainController::class, 'storeFtp'])->name('domains.ftp.store');
        Route::delete('/domains/{domain}/ftp/{ftpAccount}', [DomainController::class, 'destroyFtp'])->name('domains.ftp.destroy');
    });
    Route::post('/domains/{domain}/fix-permissions', [DomainController::class, 'fixPermissions'])->name('domains.fix-permissions');
    Route::post('/domains/{domain}/open-basedir', [DomainController::class, 'toggleOpenBasedir'])->name('domains.open-basedir');
    Route::get('/subdomains', [DomainController::class, 'subdomains'])->name('subdomains.index');
    Route::post('/subdomains', [DomainController::class, 'storeSubdomain'])->name('subdomains.store');

    // Domain Zero-Downtime Deployment
    Route::get('/domains/{domain}/deploy', [DomainDeploymentController::class, 'show'])->name('domains.deploy.show');
    Route::put('/domains/{domain}/deploy/config', [DomainDeploymentController::class, 'updateConfig'])->name('domains.deploy.config');
    Route::post('/domains/{domain}/deploy/key', [DomainDeploymentController::class, 'generateDeployKey'])->name('domains.deploy.key');
    Route::post('/domains/{domain}/deploy/run', [DomainDeploymentController::class, 'deploy'])->name('domains.deploy.run');
    Route::post('/domains/{domain}/deploy/cancel/{deployment}', [DomainDeploymentController::class, 'cancel'])->name('domains.deploy.cancel');
    Route::post('/domains/{domain}/deploy/rollback/{deployment}', [DomainDeploymentController::class, 'rollback'])->name('domains.deploy.rollback');
    Route::get('/domains/{domain}/deploy/logs/{deployment}', [DomainDeploymentController::class, 'log'])->name('domains.deploy.log');
    Route::get('/domains/{domain}/deploy/env', [DomainDeploymentEnvController::class, 'show'])->name('domains.deploy.env.show');
    Route::put('/domains/{domain}/deploy/env', [DomainDeploymentEnvController::class, 'update'])->name('domains.deploy.env.update');
    Route::post('/domains/{domain}/deploy/hooks', [DomainDeploymentHookController::class, 'store'])->name('domains.deploy.hooks.store');
    Route::put('/domains/{domain}/deploy/hooks/{hook}', [DomainDeploymentHookController::class, 'update'])->name('domains.deploy.hooks.update');
    Route::delete('/domains/{domain}/deploy/hooks/{hook}', [DomainDeploymentHookController::class, 'destroy'])->name('domains.deploy.hooks.destroy');

    // PHP Management
    Route::get('/php/ini', [PhpController::class, 'ini'])->name('php.ini');
    Route::get('/php/manager', [PhpController::class, 'manager'])->name('php.manager');
    Route::get('/php/extensions', [PhpController::class, 'extensions'])->name('php.extensions');

    // MySQL Databases
    Route::get('/databases', [DatabaseController::class, 'index'])->name('databases.index');
    Route::post('/databases', [DatabaseController::class, 'store'])->name('databases.store');
    Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
    Route::post('/databases/users', [DatabaseController::class, 'storeUser'])->name('databases.users.store');
    Route::post('/databases/users/{dbUser}/password', [DatabaseController::class, 'updatePassword'])->name('databases.users.password');
    Route::delete('/databases/users/{dbUser}', [DatabaseController::class, 'destroyUser'])->name('databases.users.destroy');
    Route::post('/databases/grant', [DatabaseController::class, 'grantPrivileges'])->name('databases.grant');
    Route::get('/phpmyadmin/sso', [DatabaseController::class, 'phpmyadminSso'])->name('phpmyadmin.sso');

    // Email Management
    Route::middleware('module:mail_core')->group(function () {
        Route::get('/email/domains', [MailDomainController::class, 'index'])->name('email.domains.index');
        Route::post('/email/domains', [MailDomainController::class, 'store'])->name('email.domains.store');
        Route::put('/email/domains/{mailDomain}', [MailDomainController::class, 'update'])->name('email.domains.update');
        Route::get('/email/accounts', [MailAccountController::class, 'index'])->name('email.accounts.index');
        Route::get('/email/domains/dns-challenge', [MailAccountController::class, 'dnsVerificationChallenge'])->name('email.domains.dns-challenge');
        Route::post('/email/domains/{mailDomain}/issue-tls', [MailAccountController::class, 'issueStandaloneTls'])->name('email.domains.issue-tls');
        Route::post('/email/accounts', [MailAccountController::class, 'store'])->name('email.accounts.store');
        Route::put('/email/accounts/{mailAccount}', [MailAccountController::class, 'update'])->name('email.accounts.update');
        Route::delete('/email/accounts/{mailAccount}', [MailAccountController::class, 'destroy'])->name('email.accounts.destroy');
        Route::get('/email/forwarders', [MailForwarderController::class, 'index'])->name('email.forwarders.index');
        Route::post('/email/forwarders', [MailForwarderController::class, 'store'])->name('email.forwarders.store');
        Route::put('/email/forwarders/{mailAlias}', [MailForwarderController::class, 'update'])->name('email.forwarders.update');
        Route::delete('/email/forwarders/{mailAlias}', [MailForwarderController::class, 'destroy'])->name('email.forwarders.destroy');
        Route::get('/email/dkim', [MailDkimController::class, 'index'])->name('email.dkim.index');
        Route::post('/email/dkim', [MailDkimController::class, 'store'])->name('email.dkim.store');
        Route::get('/email/spam', [MailSpamFilterController::class, 'index'])->name('email.spam.index');
        Route::get('/email/spam-filter', [MailSpamFilterController::class, 'index'])->name('email.spam-filter.index');
        Route::put('/email/domains/{mailDomain}/spam-policy', [MailSpamFilterController::class, 'update'])->name('email.domains.spam-policy.update');
    });

    // FTP Management
    Route::middleware('module:ftp')->group(function () {
        Route::get('/ftp', [FtpController::class, 'index'])->name('ftp.index');
        Route::post('/ftp', [FtpController::class, 'store'])->name('ftp.store');
        Route::put('/ftp/{ftpUser}', [FtpController::class, 'update'])->name('ftp.update');
        Route::patch('/ftp/{ftpUser}/password', [FtpController::class, 'updatePassword'])->name('ftp.password');
        Route::delete('/ftp/{ftpUser}', [FtpController::class, 'destroy'])->name('ftp.destroy');
    });

    // Web File Manager
    Route::get('/files', [FileManagerController::class, 'index'])->name('files.index');
    Route::get('/files/list', [FileManagerController::class, 'list'])->name('files.list');
    Route::get('/files/content', [FileManagerController::class, 'content'])->name('files.content');
    Route::post('/files/save', [FileManagerController::class, 'save'])->name('files.save');
    Route::post('/files/create', [FileManagerController::class, 'create'])->name('files.create');
    Route::post('/files/delete', [FileManagerController::class, 'delete'])->name('files.delete');
    Route::post('/files/chmod', [FileManagerController::class, 'chmod'])->name('files.chmod');
    Route::post('/files/upload', [FileManagerController::class, 'upload'])->name('files.upload');

    // Backup & Restore
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups/create', [BackupController::class, 'create'])->name('backups.create');
    Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
    Route::post('/backups/schedules', [BackupController::class, 'storeSchedule'])->name('backups.schedules.store');
    Route::delete('/backups/schedules/{schedule}', [BackupController::class, 'destroySchedule'])->name('backups.schedules.destroy');

    // Security (User + Admin accessible)
    Route::get('/security/ssl', [SecurityController::class, 'ssl'])->name('security.ssl');
    Route::post('/security/ssl/issue', [SecurityController::class, 'issueSsl'])->name('security.ssl.issue');
    Route::post('/security/ssl/custom', [SecurityController::class, 'customSsl'])->name('security.ssl.custom');
    Route::post('/security/ssl/{domain}/renew', [SecurityController::class, 'renewSsl'])->name('security.ssl.renew');
    Route::get('/security/password', [SecurityController::class, 'password'])->name('security.password');
    Route::post('/security/password', [SecurityController::class, 'updatePassword'])->name('security.password.update');
    Route::get('/security/two-factor', [SecurityController::class, 'twoFactor'])->name('security.two-factor');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])->name('security.two-factor.confirm');
    Route::post('/security/two-factor/toggle', [SecurityController::class, 'toggleTwoFactor'])->name('security.two-factor.toggle');

    // Admin Only Routes
    Route::middleware('admin')->group(function () {
        // Users Management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/php-cli-version', [UserController::class, 'updatePhpCliVersion'])->name('users.php-cli-version');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('users.unsuspend');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');

        // Packages Management
        Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
        Route::put('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');

        // Admin PHP Actions
        Route::post('/php/ini', [PhpController::class, 'updateIni'])->name('php.ini.update');
        Route::get('/php/ini/raw', [PhpController::class, 'rawIni'])->name('php.ini.raw');
        Route::post('/php/ini/raw', [PhpController::class, 'updateRawIni'])->name('php.ini.raw.update');
        Route::post('/php/extensions/install', [PhpController::class, 'installExtension'])->name('php.extensions.install');
        Route::post('/php/extensions/uninstall', [PhpController::class, 'uninstallExtension'])->name('php.extensions.uninstall');
        Route::get('/php/versions', [PhpController::class, 'versions'])->name('php.versions');
        Route::post('/php/versions/install', [PhpController::class, 'installVersion'])->name('php.versions.install');
        Route::get('/php/versions/install-status', [PhpController::class, 'installStatus'])->name('php.versions.install-status');
        Route::post('/php/versions/install-status/dismiss', [PhpController::class, 'dismissInstallStatus'])->name('php.versions.install-status.dismiss');
        Route::post('/php/versions/remove', [PhpController::class, 'removeVersion'])->name('php.versions.remove');

        // Configuration (Admin Only)
        Route::get('/configuration/settings', [ConfigurationController::class, 'settings'])->name('configuration.settings');
        Route::post('/configuration/settings', [ConfigurationController::class, 'updateSettings'])->name('configuration.settings.update');
        Route::get('/configuration/tuning', [ConfigurationController::class, 'tuning'])->name('configuration.tuning');
        Route::post('/configuration/tuning', [ConfigurationController::class, 'updateTuning'])->name('configuration.tuning.update');
        Route::get('/configuration/tuning/site/{domain}', [ConfigurationController::class, 'showSiteTuning'])->name('configuration.tuning.site.show');
        Route::post('/configuration/tuning/site/{domain}', [ConfigurationController::class, 'updateSiteTuning'])->name('configuration.tuning.site.update');
        Route::get('/configuration/error-logs', [ConfigurationController::class, 'errorLogs'])->name('configuration.error-logs');

        // Server Management (Admin Only)
        Route::get('/server/services', [ServerController::class, 'services'])->name('server.services');
        Route::get('/server/ols-status', [ServerController::class, 'olsStatus'])->name('server.ols-status');
        Route::post('/server/services/{service}/{action}', [ServerController::class, 'serviceAction'])->name('server.services.action');
        Route::post('/server/webadmin/enable', [ServerController::class, 'enableWebAdmin'])->name('server.webadmin.enable');
        Route::post('/server/webadmin/disable', [ServerController::class, 'disableWebAdmin'])->name('server.webadmin.disable');
        Route::get('/server/processes', [ServerController::class, 'processes'])->name('server.processes');
        Route::post('/server/processes/{pid}/kill', [ServerController::class, 'killProcess'])->name('server.processes.kill');
        Route::get('/server/timezone', [ServerController::class, 'timezone'])->name('server.timezone');
        Route::post('/server/timezone', [ServerController::class, 'updateTimezone'])->name('server.timezone.update');
        Route::get('/server/hostname', [ServerController::class, 'hostname'])->name('server.hostname');
        Route::post('/server/hostname', [ServerController::class, 'updateHostname'])->name('server.hostname.update');
        Route::get('/server/restart', [ServerController::class, 'restart'])->name('server.restart');
        Route::post('/server/restart', [ServerController::class, 'executeRestart'])->name('server.restart.execute');
        Route::get('/server/statistics', [ServerController::class, 'statistics'])->name('server.statistics');

        // Security (Admin Only)
        Route::get('/security/ufw', [SecurityController::class, 'ufw'])->name('security.ufw');
        Route::post('/security/ufw/rules', [SecurityController::class, 'storeUfwRule'])->name('security.ufw.store');
        Route::delete('/security/ufw/rules/{rule}', [SecurityController::class, 'deleteUfwRule'])->name('security.ufw.destroy');
        Route::get('/security/port', [SecurityController::class, 'portFirewall'])->name('security.port');
        Route::get('/security/panel-port', [SecurityController::class, 'panelPort'])->name('security.panel-port');
        Route::post('/security/panel-port', [SecurityController::class, 'updatePanelPort'])->name('security.panel-port.update');
        Route::get('/security/ssh-port', [SecurityController::class, 'sshPort'])->name('security.ssh-port');
        Route::post('/security/ssh-port', [SecurityController::class, 'updateSshPort'])->name('security.ssh-port.update');
        Route::get('/security/panel-ssl', [SecurityController::class, 'panelSsl'])->name('security.panel-ssl');
        Route::post('/security/panel-ssl', [SecurityController::class, 'updatePanelSsl'])->name('security.panel-ssl.update');
        Route::get('/security/modsecurity', [SecurityController::class, 'modSecurity'])->name('security.modsecurity');
        Route::get('/security/imunifyav', [SecurityController::class, 'imunifyAv'])->name('security.imunifyav');

        // Module Manager (Admin Only)
        Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::get('/modules/install-status', [ModuleController::class, 'installStatus'])->name('modules.install-status');
        Route::post('/modules/install-status/dismiss', [ModuleController::class, 'dismissInstallStatus'])->name('modules.install-status.dismiss');
        Route::post('/modules/{module}/install', [ModuleController::class, 'install'])->name('modules.install');
        Route::post('/modules/{module}/remove', [ModuleController::class, 'remove'])->name('modules.remove');

        // DNS Management (Admin Only + requires dns module)
        Route::middleware('module:dns')->group(function () {
            // PowerDNS Daemon & HTTP API Configuration
            Route::get('/dns/settings', [PdnsConfigController::class, 'index'])->name('dns.settings');
            Route::post('/dns/settings', [PdnsConfigController::class, 'update'])->name('dns.settings.update');
            Route::post('/dns/settings/rotate-key', [PdnsConfigController::class, 'rotateKey'])->name('dns.settings.rotate-key');
            Route::post('/dns/settings/test-connection', [PdnsConfigController::class, 'testConnection'])->name('dns.settings.test-connection');
            Route::put('/dns/settings/cache/flush', [PdnsConfigController::class, 'flushCache'])->name('dns.settings.cache.flush');

            // PowerDNS Autoprimaries / Supermasters
            Route::post('/dns/autoprimaries', [PdnsAutoprimaryController::class, 'store'])->name('dns.autoprimaries.store');
            Route::delete('/dns/autoprimaries/{ip}/{nameserver}', [PdnsAutoprimaryController::class, 'destroy'])
                ->where(['ip' => '[^/]+', 'nameserver' => '[^/]+'])
                ->name('dns.autoprimaries.destroy');

            // PowerDNS Zone Manager & Record Editor
            Route::get('/dns/zones', [PdnsZoneController::class, 'index'])->name('dns.zones.index');
            Route::post('/dns/zones', [PdnsZoneController::class, 'store'])->name('dns.zones.store');
            Route::get('/dns/zones/{zone}/records', [PdnsRecordController::class, 'show'])->where('zone', '[^/]+')->name('dns.zones.records.show');
            Route::patch('/dns/zones/{zone}/records', [PdnsRecordController::class, 'updateRrsets'])->where('zone', '[^/]+')->name('dns.zones.records.patch');
            Route::put('/dns/zones/{zone}/soa', [PdnsRecordController::class, 'updateSoa'])->where('zone', '[^/]+')->name('dns.zones.soa.update');
            Route::put('/dns/zones/{zone}/mode', [PdnsZoneModeController::class, 'updateMode'])->where('zone', '[^/]+')->name('dns.zones.mode.update');
            Route::put('/dns/zones/{zone}/notify', [PdnsZoneModeController::class, 'notify'])->where('zone', '[^/]+')->name('dns.zones.notify');
            Route::put('/dns/zones/{zone}/axfr-retrieve', [PdnsZoneModeController::class, 'axfrRetrieve'])->where('zone', '[^/]+')->name('dns.zones.axfr-retrieve');
            Route::put('/dns/zones/{zone}/rectify', [PdnsZoneModeController::class, 'rectify'])->where('zone', '[^/]+')->name('dns.zones.rectify');
            Route::put('/dns/zones/{zone}/cache/flush', [PdnsZoneModeController::class, 'flushCache'])->where('zone', '[^/]+')->name('dns.zones.cache.flush');
            Route::get('/dns/zones/{zone}/export', [PdnsZoneController::class, 'export'])->where('zone', '[^/]+')->name('dns.zones.export');
            Route::delete('/dns/zones/{zone}', [PdnsZoneController::class, 'destroy'])->where('zone', '[^/]+')->name('dns.zones.destroy');

            // PowerDNS Default Nameservers & Zone Templates
            Route::get('/dns/templates', [PdnsTemplateController::class, 'index'])->name('dns.templates.index');
            Route::post('/dns/templates', [PdnsTemplateController::class, 'store'])->name('dns.templates.store');
            Route::put('/dns/templates/{template}', [PdnsTemplateController::class, 'update'])->name('dns.templates.update');
            Route::post('/dns/templates/{template}/default', [PdnsTemplateController::class, 'setDefault'])->name('dns.templates.default');
            Route::delete('/dns/templates/{template}', [PdnsTemplateController::class, 'destroy'])->name('dns.templates.destroy');
            Route::post('/dns/nameservers', [PdnsTemplateController::class, 'updateNameservers'])->name('dns.nameservers.update');
        });
    });
});
