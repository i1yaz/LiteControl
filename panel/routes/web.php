<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ThemeController;

Route::post('/theme/switch', [ThemeController::class, 'switch'])->name('theme.switch');

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
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
    Route::get('/domains/{domain}/htaccess', [DomainController::class, 'getHtaccess'])->name('domains.htaccess.get');
    Route::post('/domains/{domain}/htaccess', [DomainController::class, 'saveHtaccess'])->name('domains.htaccess.save');
    Route::post('/domains/{domain}/ftp', [DomainController::class, 'storeFtp'])->name('domains.ftp.store');
    Route::delete('/domains/{domain}/ftp/{ftpAccount}', [DomainController::class, 'destroyFtp'])->name('domains.ftp.destroy');
    Route::post('/domains/{domain}/fix-permissions', [DomainController::class, 'fixPermissions'])->name('domains.fix-permissions');
    Route::get('/subdomains', [DomainController::class, 'subdomains'])->name('subdomains.index');
    Route::post('/subdomains', [DomainController::class, 'storeSubdomain'])->name('subdomains.store');

    // PHP Management
    Route::get('/php/ini', [PhpController::class, 'ini'])->name('php.ini');
    Route::get('/php/manager', [PhpController::class, 'manager'])->name('php.manager');
    Route::post('/php/manager', [PhpController::class, 'batchSwitch'])->name('php.manager.batch');
    Route::get('/php/extensions', [PhpController::class, 'extensions'])->name('php.extensions');
    Route::get('/php/modules', [PhpController::class, 'modules'])->name('php.modules');

    // MySQL Databases
    Route::get('/databases', [DatabaseController::class, 'index'])->name('databases.index');
    Route::post('/databases', [DatabaseController::class, 'store'])->name('databases.store');
    Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
    Route::post('/databases/users', [DatabaseController::class, 'storeUser'])->name('databases.users.store');
    Route::post('/databases/users/{dbUser}/password', [DatabaseController::class, 'updatePassword'])->name('databases.users.password');
    Route::delete('/databases/users/{dbUser}', [DatabaseController::class, 'destroyUser'])->name('databases.users.destroy');
    Route::post('/databases/grant', [DatabaseController::class, 'grantPrivileges'])->name('databases.grant');

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
    Route::post('/security/two-factor/toggle', [SecurityController::class, 'toggleTwoFactor'])->name('security.two-factor.toggle');

    // Admin Only Routes
    Route::middleware('admin')->group(function () {
        // Users Management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
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
        Route::post('/php/extensions/toggle', [PhpController::class, 'toggleExtension'])->name('php.extensions.toggle');
        Route::get('/php/versions', [PhpController::class, 'versions'])->name('php.versions');
        Route::post('/php/versions/install', [PhpController::class, 'installVersion'])->name('php.versions.install');
        Route::post('/php/versions/remove', [PhpController::class, 'removeVersion'])->name('php.versions.remove');

        // Configuration (Admin Only)
        Route::get('/configuration/settings', [ConfigurationController::class, 'settings'])->name('configuration.settings');
        Route::post('/configuration/settings', [ConfigurationController::class, 'updateSettings'])->name('configuration.settings.update');
        Route::get('/configuration/tuning', [ConfigurationController::class, 'tuning'])->name('configuration.tuning');
        Route::post('/configuration/tuning', [ConfigurationController::class, 'updateTuning'])->name('configuration.tuning.update');
        Route::get('/configuration/php-limit', [ConfigurationController::class, 'phpLimit'])->name('configuration.php-limit');
        Route::post('/configuration/php-limit', [ConfigurationController::class, 'updatePhpLimit'])->name('configuration.php-limit.update');
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
        Route::get('/security/csf', [SecurityController::class, 'csf'])->name('security.csf');
        Route::get('/security/modsecurity', [SecurityController::class, 'modSecurity'])->name('security.modsecurity');
        Route::get('/security/imunifyav', [SecurityController::class, 'imunifyAv'])->name('security.imunifyav');

        // Module Manager (Admin Only)
        Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::post('/modules/{module}/install', [ModuleController::class, 'install'])->name('modules.install');
        Route::post('/modules/{module}/remove', [ModuleController::class, 'remove'])->name('modules.remove');
    });
});
