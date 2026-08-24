<?php

namespace App\Game\Enums;

enum RunMode: string
{
    case Mob = 'mob';
    case Quest = 'quest';
    case QuestList = 'quest-list';

    /** User-authored target list (or inline names). */
    case PvpAttackList = 'pvp-attack-list';

    /** The crew's shared hitlist — one request, hundreds of hashed targets. */
    case PvpCrewHitlist = 'pvp-crew-hitlist';

    /** Every member of a nominated crew. */
    case PvpCrewMembers = 'pvp-crew-members';

    /** Fortnightly PvP Brawl (level 85). */
    case PvpBrawl = 'pvp-brawl';

    /** Fortnightly Faction Brawl (level 95). */
    case PvpFactionBrawl = 'pvp-faction-brawl';

    /** Whether this mode is one of the PvP family. */
    public function isPvp(): bool
    {
        return in_array($this, self::pvpModes(), true);
    }

    /**
     * @return list<self>
     */
    public static function pvpModes(): array
    {
        return [
            self::PvpAttackList,
            self::PvpCrewHitlist,
            self::PvpCrewMembers,
            self::PvpBrawl,
            self::PvpFactionBrawl,
        ];
    }

    /** The brawl this mode runs, when it is a brawl mode. */
    public function brawlType(): ?BrawlType
    {
        return match ($this) {
            self::PvpBrawl => BrawlType::Pvp,
            self::PvpFactionBrawl => BrawlType::Faction,
            default => null,
        };
    }
}
