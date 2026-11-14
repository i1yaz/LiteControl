<?php

declare(strict_types=1);

namespace App\Domains\Backup\Listeners;

use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Events\UserDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

class CleanupUserBackupsListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(UserDeleted $event): void
    {
        Backup::where('user_id', $event->userId)->delete();
    }
}
