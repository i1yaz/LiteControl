<?php

namespace App\Domains\HostingPlans\Policies;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Package $package): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isReseller();
    }

    public function update(User $user, Package $package): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->isAdmin();
    }
}
