<?php

namespace App\Game\Combat\Targets;

use App\Game\Engine\PvpRunConfig;
use App\Game\Enums\RunMode;
use App\Game\Exceptions\GameException;
use App\Models\AttackList;
use App\Models\Character;

/**
 * Maps a PvP run mode + config onto the target source that feeds it. The one
 * place the five modes differ, so the runner and the jobs stay identical.
 */
class PvpTargetSourceFactory
{
    public static function for(Character $character, RunMode $mode, PvpRunConfig $config): PvpTargetSource
    {
        return match ($mode) {
            RunMode::PvpAttackList => self::attackList($character, $config),
            RunMode::PvpCrewHitlist => HitlistTargetSource::crew($character),
            RunMode::PvpCrewMembers => self::crewMembers($character, $config),
            RunMode::PvpBrawl, RunMode::PvpFactionBrawl => BrawlTargetSource::forType(
                $character,
                $mode->brawlType(),
            ),
            default => throw new GameException("Run mode {$mode->value} is not a PvP mode."),
        };
    }

    /**
     * A saved list when one is configured, otherwise the names supplied
     * inline — the CLI and quick one-off runs use the latter.
     */
    private static function attackList(Character $character, PvpRunConfig $config): PvpTargetSource
    {
        if ($config->attackListId !== null) {
            $list = AttackList::with('targets')->find($config->attackListId);

            if ($list === null) {
                throw new GameException("Attack list {$config->attackListId} no longer exists.");
            }

            return AttackListTargetSource::forList($character, $list);
        }

        return NameListTargetSource::forNames($character, $config->targets);
    }

    private static function crewMembers(Character $character, PvpRunConfig $config): PvpTargetSource
    {
        if ($config->crewGameId === null) {
            throw new GameException('Crew-members mode needs a crew id.');
        }

        return CrewMembersTargetSource::forCrew($character, $config->crewGameId);
    }
}
