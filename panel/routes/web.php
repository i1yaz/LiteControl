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
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/php', [DomainController::class, 'switchPhp'])->name('domains.php');
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
    Route::post('/files/upload', [FileManagerController::class, 'upload'])->name('files.upload');

    // Backup & Restore
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups/create', [BackupController::class, 'create'])->name('backups.create');
    Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
    Route::post('/backups/schedules', [BackupController::class, 'storeSchedule'])->name('backups.schedules.store');
    Route::delete('/backups/schedules/{schedule}', [BackupController::class, 'destroySchedule'])->name('backups.schedules.destroy');

    // Admin Only Routes
    Route::middleware('admin')->group(function () {
        // Users Management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
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
    });
});
