<?php

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() || $actor->isReseller();
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($actor->isReseller()) {
            return $actor->id === $target->id || ($target->reseller_id ?? null) === $actor->id;
        }

        return $actor->id === $target->id;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin() || $actor->isReseller();
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($actor->isReseller()) {
            return ! $target->isAdmin() && ($actor->id === $target->id || ($target->reseller_id ?? null) === $actor->id);
        }

        return $actor->id === $target->id;
    }

    public function delete(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($actor->isAdmin()) {
            return true;
        }

        if ($actor->isReseller()) {
            return ! $target->isAdmin() && ($target->reseller_id ?? null) === $actor->id;
        }

        return false;
    }
}
