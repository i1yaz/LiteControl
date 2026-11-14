<?php

declare(strict_types=1);

namespace App\Domains\Database\Listeners;

use App\Domains\Database\Models\Database;
use App\Domains\Database\Models\DatabaseUser;
use App\Domains\Identity\Events\UserDeleted;

class CleanupUserDatabasesListener
{
    public function handle(UserDeleted $event): void
    {
        Database::where('user_id', $event->userId)->delete();
        DatabaseUser::where('user_id', $event->userId)->delete();
    }
}
