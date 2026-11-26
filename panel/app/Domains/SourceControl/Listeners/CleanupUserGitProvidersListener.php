<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Listeners;

use App\Domains\Identity\Events\UserDeleted;
use App\Domains\SourceControl\Models\GitProvider;

class CleanupUserGitProvidersListener
{
    public function handle(UserDeleted $event): void
    {
        GitProvider::query()
            ->forUser($event->userId)
            ->delete();
    }
}
