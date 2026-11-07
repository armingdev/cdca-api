<?php

namespace App\Policies;

use App\Models\CrewGroup;
use App\Models\User;

/**
 * Members see the group and manage their own shares; only the leader runs
 * the group and reads the shared sessions.
 */
class CrewGroupPolicy
{
    public function view(User $user, CrewGroup $crewGroup): bool
    {
        return $crewGroup->hasMember($user);
    }

    public function update(User $user, CrewGroup $crewGroup): bool
    {
        return $crewGroup->isLedBy($user);
    }

    public function delete(User $user, CrewGroup $crewGroup): bool
    {
        return $crewGroup->isLedBy($user);
    }

    /** Choose which of one's own RGAs the leader may read. */
    public function share(User $user, CrewGroup $crewGroup): bool
    {
        return $crewGroup->hasMember($user);
    }

    /** The whole point of the group — and the only way a session leaves its owner. */
    public function readSessions(User $user, CrewGroup $crewGroup): bool
    {
        return $crewGroup->isLedBy($user);
    }

    /**
     * The leader removes others; anyone else may only remove themselves. The
     * leader cannot leave (disband the group instead).
     */
    public function removeMember(User $user, CrewGroup $crewGroup, User $member): bool
    {
        if ($crewGroup->isLedBy($member)) {
            return false;
        }

        return $crewGroup->isLedBy($user) || $user->is($member);
    }
}
