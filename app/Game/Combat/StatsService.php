<?php

namespace App\Game\Combat;

use App\Game\Data\UserStats;
use App\Game\Http\GameClient;
use App\Game\Parsers\UserStatsParser;
use App\Models\Character;

/**
 * Rage/exp/level refresh via userstats.php — the canonical source; never
 * trust stale values after an action. Also owns the level-up action, which
 * refills rage as a side effect (the basis of the "level if rage low" policy).
 */
class StatsService
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly UserStatsParser $parser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(UserStatsParser::class));
    }

    public function refresh(): UserStats
    {
        $stats = $this->parser->parse($this->client->get('userstats.php')->body());

        $this->character->update([
            'rage' => $stats->rage,
            'exp' => $stats->exp,
            'level' => $stats->level,
            'last_stats_at' => now(),
        ]);

        return $stats;
    }

    /**
     * GET levelup.php levels the character when eligible and auto-refills
     * rage. Returns whether a level-up actually happened.
     */
    public function tryLevelUp(): bool
    {
        $body = $this->client->get('levelup.php')->body();

        if (! str_contains($body, 'You are now Level')) {
            return false;
        }

        $this->refresh();

        return true;
    }
}
