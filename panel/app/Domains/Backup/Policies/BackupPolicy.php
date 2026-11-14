<?php

namespace App\Domains\Backup\Policies;

use App\Domains\Backup\Models\Backup;
use App\Domains\Identity\Models\User;

class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Backup $backup): bool
    {
        return $this->canAccess($user, $backup);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Backup $backup): bool
    {
        return $this->canAccess($user, $backup);
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $this->canAccess($user, $backup);
    }

    private function canAccess(User $user, Backup $backup): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $backup->user_id === $user->id
                || ($backup->user?->reseller_id ?? null) === $user->id;
        }

        return $backup->user_id === $user->id;
    }
}
