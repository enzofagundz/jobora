<?php

namespace App\Policies;

use App\Models\DiscoverySource;
use App\Models\User;

class DiscoverySourcePolicy
{
    public function view(User $user, DiscoverySource $source): bool
    {
        return $source->user_id === $user->id;
    }

    public function update(User $user, DiscoverySource $source): bool
    {
        return $source->user_id === $user->id && ! $source->trashed();
    }

    public function delete(User $user, DiscoverySource $source): bool
    {
        return $source->user_id === $user->id && ! $source->trashed();
    }
}
