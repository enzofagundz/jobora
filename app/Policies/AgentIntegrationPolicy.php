<?php

namespace App\Policies;

use App\Models\AgentIntegration;
use App\Models\User;

class AgentIntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentIntegration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    public function revoke(User $user, AgentIntegration $integration): bool
    {
        return $integration->user_id === $user->id;
    }
}
