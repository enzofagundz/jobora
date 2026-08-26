<?php

namespace App\Policies;

use App\Models\JobCapture;
use App\Models\User;

class JobCapturePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobCapture $capture): bool
    {
        return $capture->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobCapture $capture): bool
    {
        return $capture->user_id === $user->id;
    }

    public function delete(User $user, JobCapture $capture): bool
    {
        return $capture->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function triage(User $user, JobCapture $capture): bool
    {
        return $capture->user_id === $user->id;
    }

    public function promote(User $user, JobCapture $capture): bool
    {
        return $capture->user_id === $user->id;
    }
}
