<?php

namespace App\Policies;

use App\Models\QuestList;
use App\Models\User;

class QuestListPolicy
{
    /** Shared lists (published, or built-in) are readable by everyone; changing one stays the owner's. */
    public function view(User $user, QuestList $questList): bool
    {
        return $questList->user_id === $user->id || $questList->isShared();
    }

    /** Copying is how a shared list becomes one the user can edit and run. */
    public function copy(User $user, QuestList $questList): bool
    {
        return $this->view($user, $questList);
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
