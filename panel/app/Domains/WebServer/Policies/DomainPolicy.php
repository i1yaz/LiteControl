<?php

namespace App\Domains\WebServer\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;

class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
    {
        return $this->canAccess($user, $domain);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->canAccess($user, $domain);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->canAccess($user, $domain);
    }

    private function canAccess(User $user, Domain $domain): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $domain->user_id === $user->id
                || ($domain->user?->reseller_id ?? null) === $user->id;
        }

        return $domain->user_id === $user->id;
    }
}
