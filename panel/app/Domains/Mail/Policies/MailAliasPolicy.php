<?php

declare(strict_types=1);

namespace App\Domains\Mail\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAlias;

class MailAliasPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MailAlias $mailAlias): bool
    {
        return $this->canAccess($user, $mailAlias);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MailAlias $mailAlias): bool
    {
        return $this->canAccess($user, $mailAlias);
    }

    public function delete(User $user, MailAlias $mailAlias): bool
    {
        return $this->canAccess($user, $mailAlias);
    }

    private function canAccess(User $user, MailAlias $mailAlias): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $mailAlias->mailDomain?->user_id === $user->id;
    }
}
