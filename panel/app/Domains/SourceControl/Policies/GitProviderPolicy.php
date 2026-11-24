<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\SourceControl\Models\GitProvider;

class GitProviderPolicy
{
    public function create(User $user): bool
    {
        $max = (int) config('litecontrol.max_git_providers_per_user', 10);

        return GitProvider::query()->forUser($user->id)->count() < $max;
    }

    public function view(User $user, GitProvider $provider): bool
    {
        return $provider->user_id === $user->id;
    }

    public function update(User $user, GitProvider $provider): bool
    {
        return $provider->user_id === $user->id;
    }

    public function delete(User $user, GitProvider $provider): bool
    {
        return $provider->user_id === $user->id;
    }

    public function bind(User $user, GitProvider $provider): bool
    {
        return $provider->user_id === $user->id;
    }
}
