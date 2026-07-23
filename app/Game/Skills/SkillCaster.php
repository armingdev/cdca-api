<?php

namespace App\Game\Skills;

use App\Game\Http\GameClient;
use App\Game\Parsers\CastConfirmationParser;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Closure;

/**
 * Casts skills for one character and tracks per-skill cooldown/buff windows so
 * the engine knows when a skill can be re-cast and whether a buff (e.g.
 * Circumspect) is currently active. Backs "cast all selected skills on start"
 * and "run only when Circumspect active".
 */
class SkillCaster
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly CastConfirmationParser $parser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(CastConfirmationParser::class));
    }

    /**
     * Cast one skill now. Records last_cast_at on success. Returns whether the
     * game confirmed the cast.
     */
    public function cast(Skill $skill): bool
    {
        $response = $this->client->post('cast_skills.php', [
            'castskillid' => $skill->id,
            'cast' => 'Cast Skill',
        ]);

        if (! $this->parser->castSucceeded($response->body())) {
            return false;
        }

        $this->stateFor($skill)->update([
            'last_cast_at' => now(),
            'recharge_until' => null,
            'buff_until' => null,
        ]);

        return true;
    }

    /**
     * Cast every skill the character selected for run-start that is not
     * already an active buff and is off cooldown.
     *
     * @param  Closure(string): void|null  $log
     * @return int number of skills successfully cast
     */
    public function castOnStart(?Closure $log = null): int
    {
        $log ??= fn (string $message) => null;
        $cast = 0;

        $selected = CharacterSkill::with('skill')
            ->where('character_id', $this->character->id)
            ->where('cast_on_start', true)
            ->get();

        foreach ($selected as $state) {
            if ($state->synced_at !== null && ! $state->isCastable()) {
                $log("{$state->skill->name} not trained — skipping.");

                continue;
            }

            if ($state->isBuffActive()) {
                $log("{$state->skill->name} already active — skipping.");

                continue;
            }

            if ($state->isOnCooldown()) {
                $log("{$state->skill->name} on cooldown — skipping.");

                continue;
            }

            if ($this->cast($state->skill)) {
                $cast++;
                $log("Cast {$state->skill->name}.");
            } else {
                $log("Failed to cast {$state->skill->name} (rage, cooldown, or not learned).");
            }
        }

        return $cast;
    }

    public function isCircumspectActive(): bool
    {
        $state = CharacterSkill::with('skill')
            ->where('character_id', $this->character->id)
            ->where('skill_id', Skill::CIRCUMSPECT_ID)
            ->first();

        return $state !== null && $state->isBuffActive();
    }

    /**
     * Make sure Circumspect is active: already active → true; off cooldown →
     * cast it; on cooldown with no active buff → false (cannot make it up).
     */
    public function ensureCircumspect(?Closure $log = null): bool
    {
        $log ??= fn (string $message) => null;

        if ($this->isCircumspectActive()) {
            return true;
        }

        $circumspect = Skill::find(Skill::CIRCUMSPECT_ID);

        if ($circumspect === null) {
            return false;
        }

        if ($this->stateFor($circumspect)->isOnCooldown()) {
            $log('Circumspect is on cooldown and not active.');

            return false;
        }

        $cast = $this->cast($circumspect);
        $log($cast ? 'Cast Circumspect.' : 'Failed to cast Circumspect.');

        return $cast;
    }

    private function stateFor(Skill $skill): CharacterSkill
    {
        return CharacterSkill::firstOrCreate([
            'character_id' => $this->character->id,
            'skill_id' => $skill->id,
        ]);
    }
}
