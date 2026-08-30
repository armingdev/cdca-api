<?php

namespace App\Game\Skills;

use App\Game\Http\GameClient;
use App\Game\Parsers\CastConfirmationParser;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Closure;

/**
 * Casts a single skill for one character and answers the Circumspect gate.
 *
 * Casting the whole selected set lives in BuffEnsurer, which owns the
 * syncing, ordering, and rage budgeting that a reliable pass needs; this
 * class stays the one place a cast is actually issued and recorded.
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
     * Cast one skill now. Records last_cast_at only when the game confirmed
     * *this* skill by name. Returns whether the cast went off.
     */
    public function cast(Skill $skill): bool
    {
        $response = $this->client->post('cast_skills.php', [
            'castskillid' => $skill->id,
            'cast' => 'Cast Skill',
        ]);

        if (! $this->parser->castSucceededFor($response->body(), $skill->name)) {
            return false;
        }

        // The server windows we hold describe the state *before* this cast;
        // clearing them re-arms the local estimate (see CharacterSkill).
        $this->stateFor($skill)->update([
            'last_cast_at' => now(),
            'recharge_until' => null,
            'recharge_synced_at' => null,
            'buff_until' => null,
            'buff_synced_at' => null,
        ]);

        return true;
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
