<?php

namespace App\Policies;

use App\Models\JobPosting;
use App\Models\User;

class JobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobPosting $posting): bool
    {
        return $posting->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobPosting $posting): bool
    {
        return $posting->user_id === $user->id;
    }

    public function delete(User $user, JobPosting $posting): bool
    {
        return $posting->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}
