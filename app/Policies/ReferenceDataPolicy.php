<?php

namespace App\Policies;

use App\Models\User;

class ReferenceDataPolicy
{
    public function manageReferenceData(User $user): bool
    {
        return $user->staff()->exists() && $user->tokenCan('staff');
    }
}
