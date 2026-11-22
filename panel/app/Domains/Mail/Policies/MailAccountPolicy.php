<?php

declare(strict_types=1);

namespace App\Domains\Mail\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAccount;

class MailAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MailAccount $mailAccount): bool
    {
        return $this->canAccess($user, $mailAccount);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MailAccount $mailAccount): bool
    {
        return $this->canAccess($user, $mailAccount);
    }

    public function delete(User $user, MailAccount $mailAccount): bool
    {
        return $this->canAccess($user, $mailAccount);
    }

    private function canAccess(User $user, MailAccount $mailAccount): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $mailAccount->mailDomain?->user_id === $user->id;
    }
}
