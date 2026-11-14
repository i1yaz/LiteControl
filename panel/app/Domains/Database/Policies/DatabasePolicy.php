<?php

namespace App\Domains\Database\Policies;

use App\Domains\Database\Models\Database;
use App\Domains\Identity\Models\User;

class DatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Database $database): bool
    {
        return $this->canAccess($user, $database);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Database $database): bool
    {
        return $this->canAccess($user, $database);
    }

    public function delete(User $user, Database $database): bool
    {
        return $this->canAccess($user, $database);
    }

    private function canAccess(User $user, Database $database): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $database->user_id === $user->id
                || ($database->user?->reseller_id ?? null) === $user->id;
        }

        return $database->user_id === $user->id;
    }
}
