<?php

namespace App\Policies;

use App\Models\AttackList;
use App\Models\User;

class AttackListPolicy
{
    public function view(User $user, AttackList $attackList): bool
    {
        return $attackList->user_id === $user->id;
    }

    public function update(User $user, AttackList $attackList): bool
    {
        return $attackList->user_id === $user->id;
    }

    public function delete(User $user, AttackList $attackList): bool
    {
        return $attackList->user_id === $user->id;
    }
}
