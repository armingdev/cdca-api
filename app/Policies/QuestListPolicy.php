<?php

namespace App\Policies;

use App\Models\QuestList;
use App\Models\User;

class QuestListPolicy
{
    public function view(User $user, QuestList $questList): bool
    {
        return $questList->user_id === $user->id;
    }

    public function update(User $user, QuestList $questList): bool
    {
        return $questList->user_id === $user->id;
    }

    public function delete(User $user, QuestList $questList): bool
    {
        return $questList->user_id === $user->id;
    }
}
