<?php

namespace App\Game\Skills;

use App\Game\Data\SkillInfo;
use App\Game\Data\SkillsPage;
use App\Game\Data\SkillSyncResult;
use App\Game\Data\TrainResult;
use App\Game\Enums\SkillSchool;
use App\Game\Http\GameClient;
use App\Game\Parsers\SkillInfoParser;
use App\Game\Parsers\SkillsPageParser;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;

/**
 * Syncs one character's skill state from the game: trained/bonus levels from
 * the five cast_skills.php tabs, skill points, active buffs, per-skill
 * authoritative recharge from skills_info.php, and single-skill training.
 */
class SkillSyncService
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly SkillsPageParser $pageParser,
        private readonly SkillInfoParser $infoParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(SkillsPageParser::class),
            app(SkillInfoParser::class),
        );
    }

    /**
     * Fetch all five tabs (throttled GETs) and persist levels, skill points,
     * discovered skills, and active buffs. skills_info.php is only fetched
     * for catalog-unknown skills discovered on a tab.
     */
    public function sync(): SkillSyncResult
    {
        $rowsSynced = 0;
        $discovered = 0;
        $firstPage = null;

        foreach (SkillSchool::cases() as $school) {
            $page = $this->fetchTab($school);
            $firstPage ??= $page;

            [$synced, $found] = $this->persistRows($page, $school);
            $rowsSynced += $synced;
            $discovered += $found;
        }

        if ($firstPage->skillPoints !== null) {
            $this->character->update(['skill_points' => $firstPage->skillPoints]);
        }

        $this->character->update(['school' => $this->deriveSchool()]);

        $activeBuffs = $this->persistBuffs($firstPage);

        return new SkillSyncResult(
            rowsSynced: $rowsSynced,
            skillsDiscovered: $discovered,
            skillPoints: $firstPage->skillPoints,
            school: $this->character->school,
            activeBuffs: $activeBuffs,
        );
    }

    /**
     * Fetch skills_info.php for one skill and persist the character's current
     * (level-scaled) values plus the authoritative recharge window.
     */
    public function refreshSkillInfo(Skill $skill): SkillInfo
    {
        $info = $this->fetchInfo($skill->id);

        CharacterSkill::updateOrCreate(
            ['character_id' => $this->character->id, 'skill_id' => $skill->id],
            [
                'current_rage_cost' => $info->rageCost,
                'current_cooldown_minutes' => $info->cooldownMinutes,
                'current_duration_minutes' => $info->durationMinutes,
                'recharge_until' => $info->rechargingMinutesRemaining !== null
                    ? now()->addMinutes($info->rechargingMinutesRemaining)
                    : null,
                'synced_at' => now(),
            ],
        );

        return $info;
    }

    /**
     * Train one skill via GET cast_skills.php?C=2&T={id}, confirming through
     * the returned page's skill log ("Trained {name} Level {n}").
     */
    public function train(Skill $skill): TrainResult
    {
        if ($failure = $this->trainGuardrail($skill)) {
            return $failure;
        }

        $response = $this->client->get('cast_skills.php', ['C' => 2, 'T' => $skill->id]);
        $page = $this->pageParser->parse($response->body());

        $newLevel = $this->confirmedTrainLevel($page, $skill);

        if ($newLevel === null) {
            return TrainResult::failure("The game did not confirm training {$skill->name}.");
        }

        $this->persistRows($page, $skill->school);

        if ($page->skillPoints !== null) {
            $this->character->update(['skill_points' => $page->skillPoints]);
        }

        $this->character->update(['school' => $this->deriveSchool()]);

        return new TrainResult(
            success: true,
            message: "Trained {$skill->name} to level {$newLevel}.",
            newLevel: $newLevel,
            skillPointsRemaining: $page->skillPoints,
        );
    }

    /**
     * The train GET is confirmed by a fresh "Trained {name} Level {n}" entry
     * at the top of the returned page's skill log.
     */
    private function confirmedTrainLevel(SkillsPage $page, Skill $skill): ?int
    {
        foreach ($page->history as $entry) {
            if (preg_match('/^Trained '.preg_quote($skill->name, '/').' Level (\d+)$/i', $entry->action, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function trainGuardrail(Skill $skill): ?TrainResult
    {
        if ($skill->school === SkillSchool::Misc) {
            return TrainResult::failure('Misc skills are acquired through gameplay or items and cannot be trained.');
        }

        if ($skill->unlock_level !== null && $this->character->level < $skill->unlock_level) {
            return TrainResult::failure("{$skill->name} unlocks at character level {$skill->unlock_level}.");
        }

        $state = $this->character->skills()->where('skill_id', $skill->id)->first();

        if ($skill->single_level && $state?->trained_level >= 1) {
            return TrainResult::failure("{$skill->name} is a single-level skill and is already trained.");
        }

        if ($this->character->skill_points !== null && $this->character->skill_points < 1) {
            return TrainResult::failure('No skill points available.');
        }

        $lockedSchools = [SkillSchool::Ferocity, SkillSchool::Preservation, SkillSchool::Affliction];

        if (in_array($skill->school, $lockedSchools, true)
            && $this->character->school !== null
            && $this->character->school !== $skill->school) {
            return TrainResult::failure(
                "Cannot train {$skill->school->value} skills: this character is committed to {$this->character->school->value} (reset skill points first)."
            );
        }

        return null;
    }

    private function fetchTab(SkillSchool $school): SkillsPage
    {
        $query = $school->tabParam() !== null ? ['C' => $school->tabParam()] : [];

        return $this->pageParser->parse($this->client->get('cast_skills.php', $query)->body());
    }

    private function fetchInfo(int $skillId): SkillInfo
    {
        return $this->infoParser->parse($this->client->get('skills_info.php', ['id' => $skillId])->body());
    }

    /**
     * Persist one tab's rows: per-character levels, catalog upserts for
     * unknown skills (Misc discovery), and skill metadata.
     *
     * @return array{int, int} [rows synced, skills discovered]
     */
    private function persistRows(SkillsPage $page, SkillSchool $school): array
    {
        $synced = 0;
        $discovered = 0;

        foreach ($page->rows as $row) {
            $skill = Skill::find($row->id);

            if ($skill === null) {
                $skill = $this->discoverSkill($row->id, $row->name, $row->description, $school);
                $discovered++;
            }

            $metadata = ['unlock_level' => $row->unlockLevel ?? $skill->unlock_level];

            if ($school !== SkillSchool::Misc && $row->trainedLevel >= 1 && ! $row->trainable && $row->unlockLevel === null) {
                $metadata['single_level'] = true;
            }

            $skill->update($metadata);

            CharacterSkill::updateOrCreate(
                ['character_id' => $this->character->id, 'skill_id' => $row->id],
                [
                    'trained_level' => $row->trainedLevel,
                    'bonus_level' => $row->bonusLevel,
                    'synced_at' => now(),
                ],
            );
            $synced++;
        }

        return [$synced, $discovered];
    }

    /**
     * A skill on a tab that is not in the catalog yet — fetch its info and
     * upsert. Cooldown/duration stay null: the info values include this
     * character's modifiers, so only per-character current_* columns and the
     * name/rage snapshot are trustworthy catalog material.
     */
    private function discoverSkill(int $id, string $name, string $description, SkillSchool $school): Skill
    {
        $info = $this->fetchInfo($id);

        return Skill::create([
            'id' => $id,
            'name' => $name,
            'school' => $school,
            'rage_cost' => $info->rageCost,
            'cooldown_minutes' => null,
            'duration_minutes' => null,
            'description' => $description !== '' ? $description : $info->description,
        ]);
    }

    /**
     * Buff state from the Current Effects panel: matched skills get a server-
     * read buff window; every other synced row is cleared (fresh page says the
     * buff is not active).
     */
    private function persistBuffs(SkillsPage $page): int
    {
        $minutesByName = [];

        foreach ($page->currentEffects as $effect) {
            $minutesByName[$effect->name] = $effect->minutesLeft;
        }

        $active = 0;

        foreach ($this->character->skills()->with('skill')->get() as $state) {
            $minutes = $minutesByName[$state->skill->name] ?? null;

            $state->update(['buff_until' => $minutes !== null ? now()->addMinutes($minutes) : null]);

            if ($minutes !== null) {
                $active++;
            }
        }

        return $active;
    }

    /**
     * The character's committed school: the single non-Class school where
     * trained points exist. Null while uncommitted.
     */
    private function deriveSchool(): ?SkillSchool
    {
        $schools = $this->character->skills()
            ->where('trained_level', '>=', 1)
            ->with('skill')
            ->get()
            ->map(fn (CharacterSkill $state) => $state->skill->school)
            ->filter(fn (SkillSchool $school) => in_array(
                $school,
                [SkillSchool::Ferocity, SkillSchool::Preservation, SkillSchool::Affliction],
                true,
            ))
            ->unique(fn (SkillSchool $school) => $school->value);

        return $schools->count() === 1 ? $schools->first() : null;
    }
}
