<?php

namespace App\Policies;

use App\Models\Character;
use App\Models\User;

class CharacterPolicy
{
    public function view(User $user, Character $character): bool
    {
        return $this->owns($user, $character);
    }

    public function update(User $user, Character $character): bool
    {
        return $this->owns($user, $character);
    }

    private function owns(User $user, Character $character): bool
    {
        return $character->rga->user_id === $user->id;
    }
}
