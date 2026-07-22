<?php

namespace App\Policies;

use App\Models\Rga;
use App\Models\User;

class RgaPolicy
{
    public function view(User $user, Rga $rga): bool
    {
        return $rga->user_id === $user->id;
    }

    public function update(User $user, Rga $rga): bool
    {
        return $rga->user_id === $user->id;
    }

    public function delete(User $user, Rga $rga): bool
    {
        return $rga->user_id === $user->id;
    }
}
