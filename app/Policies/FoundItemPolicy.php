<?php

namespace App\Policies;

use App\Models\User;

class FoundItemPolicy
{
    public function manageItems(User $user): bool
    {
        return $user->staff()->exists() && $user->tokenCan('staff');
    }

    public function manageClaims(User $user): bool
    {
        return $this->manageItems($user);
    }
}
