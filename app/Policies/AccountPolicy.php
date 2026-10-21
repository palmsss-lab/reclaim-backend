<?php

namespace App\Policies;

use App\Models\User;

class AccountPolicy
{
    public function manageAccounts(User $user): bool
    {
        return $user->tokenCan('staff') && $user->canManageAccounts();
    }

    public function modifyAccount(User $user, User $account): bool
    {
        return $this->manageAccounts($user)
            && $account->person_id === null
            && $account->staff()->exists();
    }
}
