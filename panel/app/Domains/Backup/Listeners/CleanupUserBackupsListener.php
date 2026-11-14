<?php

declare(strict_types=1);

namespace App\Domains\Backup\Listeners;

use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Events\UserDeleted;

class CleanupUserBackupsListener
{
    public function handle(UserDeleted $event): void
    {
        Backup::where('user_id', $event->userId)->delete();
    }
}
