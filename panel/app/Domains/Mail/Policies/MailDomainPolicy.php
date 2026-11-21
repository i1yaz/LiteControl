<?php

declare(strict_types=1);

namespace App\Domains\Mail\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailDomain;

class MailDomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MailDomain $mailDomain): bool
    {
        return $this->canAccess($user, $mailDomain);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MailDomain $mailDomain): bool
    {
        return $this->canAccess($user, $mailDomain);
    }

    public function delete(User $user, MailDomain $mailDomain): bool
    {
        return $this->canAccess($user, $mailDomain);
    }

    private function canAccess(User $user, MailDomain $mailDomain): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $mailDomain->user_id === $user->id;
    }
}
