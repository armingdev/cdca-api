<?php

namespace App\Game\Parsers;

use App\Game\Data\AttackLogEntry;
use App\Game\Enums\BattleOutcome;
use App\Game\GameClock;
use App\Game\Parsers\Concerns\ParsesAttackWindows;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses `/attacklog?mode=out` (our attacks) and `?mode=in` (attacks on us).
 *
 *   Defender | Date | Result | Message | Bounty | View
 *
 * This is the authoritative per-target cooldown state and survives restarts,
 * so the engine rebuilds `next_attackable_at` from it on run start instead of
 * trusting local history.
 *
 * Dates render on the game's clock (UTC-5, no DST — see GameClock).
 *
 * Fixture: attacklog_out.html.
 */
class AttackLogParser
{
    use ParsesAttackWindows;

    /**
     * @return list<AttackLogEntry>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        foreach ($crawler->filter('tr') as $node) {
            $entry = $this->entryFromRow(new Crawler($node));

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function entryFromRow(Crawler $row): ?AttackLogEntry
    {
        $cells = $row->filter('td');

        if ($cells->count() < 3) {
            return null;
        }

        $opponent = $cells->eq(0)->filter('a');

        if ($opponent->count() === 0) {
            return null;
        }

        $playerId = $this->playerIdFromHref($opponent->attr('href'));
        $occurredAt = GameClock::parse($cells->eq(1)->text());

        if ($playerId === null || $occurredAt === null) {
            return null;
        }

        return new AttackLogEntry(
            opponentPlayerId: $playerId,
            opponentName: trim($opponent->text()),
            occurredAt: $occurredAt,
            outcome: $this->outcome($cells->eq(2)->text()),
            battleId: $this->battleId($row),
            message: $cells->count() > 3 ? trim($cells->eq(3)->text()) : '',
        );
    }

    private function outcome(string $text): BattleOutcome
    {
        return match (strtolower(trim($text))) {
            'win' => BattleOutcome::Win,
            'loss', 'lose', 'lost' => BattleOutcome::Loss,
            default => BattleOutcome::Unknown,
        };
    }

    /** The row's "View" link points at the canonical battle page. */
    private function battleId(Crawler $row): ?int
    {
        foreach ($row->filter('a[href]') as $node) {
            $href = (string) new Crawler($node)->attr('href');

            if (preg_match('#/(?:plr)?attack/(\d+)#', $href, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
