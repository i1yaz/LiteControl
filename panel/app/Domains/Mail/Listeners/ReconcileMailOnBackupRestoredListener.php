<?php

declare(strict_types=1);

namespace App\Domains\Mail\Listeners;

use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\BackupRestored;
use Illuminate\Contracts\Queue\ShouldQueue;

class ReconcileMailOnBackupRestoredListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ModuleRegistryQueryInterface $moduleRegistry,
    ) {}

    public function handle(BackupRestored $event): void
    {
        if (! $this->moduleRegistry->isActive('mail_core')) {
            return;
        }

        ReconcileMailServerJob::dispatch('all');
    }
}
