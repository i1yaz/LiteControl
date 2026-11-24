<?php

use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Jobs\SyncMailboxQuotasJob;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    if (app(ModuleRegistryQueryInterface::class)->isActive('mail_core')) {
        ReconcileMailServerJob::dispatch('all');
    }
})->name('mail:reconcile-nightly')->dailyAt('03:00')->withoutOverlapping();

Schedule::call(function (): void {
    if (app(ModuleRegistryQueryInterface::class)->isActive('mail_core')) {
        SyncMailboxQuotasJob::dispatch();
    }
})->name('mail:sync-quotas')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('source-control:check-panel-ssl-expiry')
    ->daily()
    ->withoutOverlapping();

Schedule::command('source-control:prune-abandoned-providers')
    ->weekly()
    ->withoutOverlapping();
