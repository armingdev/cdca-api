<?php

namespace App\Http\Requests;

use App\Game\Engine\QuestListRunConfig;
use App\Game\Enums\PotionKind;
use App\Game\Enums\RageReserveEvent;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunPriority;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A selection nothing will cast is a mistake worth surfacing rather than
     * silently overriding — the launcher turns cast-on-start on by itself when
     * the flag is simply absent.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('skill_ids') === [] || ! $this->has('skill_ids')) {
                    return;
                }

                if ($this->has('cast_on_start') && ! $this->boolean('cast_on_start')) {
                    $validator->errors()->add(
                        'cast_on_start',
                        'Turn on cast-on-start or leave the skill selection empty.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => [Rule::required(), Rule::enum(RunMode::class)],
            'name' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(80)],
            // Who gets a free run worker first when all are busy.
            'priority' => [Rule::sometimes(), Rule::enum(RunPriority::class)],
            'characters' => [Rule::required(), Rule::array()->min(1)],
            'characters.*' => [Rule::numeric()->integer(), Rule::exists('characters', 'id')],

            'cast_on_start' => [Rule::sometimes(), Rule::boolean()],

            // The fleet-wide selection: replaces every listed character's
            // cast-on-start set. Omitted = each keeps its own. The array-level
            // exists rule checks the whole list in one query.
            'skill_ids' => [Rule::sometimes(), Rule::array(), Rule::exists('skills', 'id')],
            'skill_ids.*' => [Rule::numeric()->integer()],

            'require_circumspect' => [Rule::sometimes(), Rule::boolean()],

            // Potions (any mode): buff potions are kept active like
            // cast-on-start skills; rage potions are drunk, in the given
            // order, instead of parking for the hourly rage tick — at most
            // max_rage_potions per run cycle.
            'buff_potion_ids' => [Rule::sometimes(), Rule::array()->max(20)],
            'buff_potion_ids.*' => [Rule::numeric()->integer(), Rule::distinct(), Rule::exists('potions', 'id')->where('kind', PotionKind::Buff->value)],
            'rage_potion_ids' => [Rule::sometimes(), Rule::array()->max(10)],
            'rage_potion_ids.*' => [Rule::numeric()->integer(), Rule::distinct(), Rule::exists('potions', 'id')->where('kind', PotionKind::Rage->value)],
            'max_rage_potions' => [
                Rule::requiredIf(fn (): bool => ! empty($this->input('rage_potion_ids'))),
                Rule::numeric()->integer()->min(1)->max(50),
            ],

            // Park ahead of these events so the rage bar is full for them.
            'reserve_rage_for' => [Rule::sometimes(), Rule::array()],
            'reserve_rage_for.*' => [Rule::enum(RageReserveEvent::class), Rule::distinct()],
            'reserve_rage_hours' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(72)],
            'restart_every_minutes' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(1)],
            'start_at' => [Rule::sometimes(), Rule::nullable(), Rule::date()],
            'stop_rage' => [Rule::sometimes(), Rule::numeric()->integer()->min(0)],
            'level_up' => [Rule::sometimes(), Rule::boolean()],
            'smart' => [Rule::sometimes(), Rule::boolean()],

            // mob mode
            'mobs' => [Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::Mob->value), Rule::array()],
            'mobs.*' => [Rule::string()->max(255)],
            'max_kills' => [Rule::sometimes(), Rule::numeric()->integer()->min(0)],
            'drop_junk' => [Rule::sometimes(), Rule::boolean()],
            'run_count' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(0)],
            'attack_interval_seconds' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(60)->max(86400)],

            // quest mode — either a quest picked from the catalog (its giver
            // and game id come from the catalog row), or the raw pair for a
            // quest the catalog does not know yet.
            'catalog_quest_id' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer(), Rule::exists('quests', 'id')],
            'npc' => [$this->requiredForRawQuest(), Rule::string()->max(255)],
            'quest_id' => [$this->requiredForRawQuest(), Rule::numeric()->integer()->min(1)],

            // quest + quest-list: pause before re-checking rooms whose targets were all dead
            'respawn_wait_seconds' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(60)->max(86400)],

            // quest + quest-list: skip steps wanting an item the game only sells
            'skip_shard_quests' => [Rule::sometimes(), Rule::boolean()],

            // quest-list mode — one or more lists, played back to back in this
            // order. The single quest_list_id is still accepted from clients
            // that predate chaining. Ownership is checked in the controller.
            'quest_list_ids' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::QuestList->value && ! $this->filled('quest_list_id')),
                Rule::array()->min(1)->max(QuestListRunConfig::MAX_LISTS),
            ],
            'quest_list_ids.*' => [Rule::numeric()->integer()->min(1), Rule::distinct()],
            'quest_list_id' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(1)],

            // pvp — attack-list mode takes either a saved list or inline names
            'attack_list_id' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer(), Rule::exists('attack_lists', 'id')],
            'targets' => [
                Rule::array(),
                // Inline names are required only when no saved list is given.
                Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::PvpAttackList->value
                    && ! $this->filled('attack_list_id')),
            ],
            'targets.*' => [Rule::string()->max(255)],

            // pvp — crew-members mode
            // One run can work through several crews' rosters. The single
            // crew_game_id is still accepted from clients that predate that.
            'crew_game_ids' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::PvpCrewMembers->value && ! $this->filled('crew_game_id')),
                Rule::array()->min(1)->max(10),
            ],
            'crew_game_ids.*' => [Rule::numeric()->integer()->min(1), Rule::distinct()],
            'crew_game_id' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(1)],

            // pvp — shared options.
            // No attack_rage: the rage cost is supplied by the server per
            // target (VERIFIED 2026-08-22), so a client value would be wrong.
            'attacks_per_target' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            'max_attacks' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(1)],
            'skip_too_strong' => [Rule::sometimes(), Rule::boolean()],
            'auto_enter_brawl' => [Rule::sometimes(), Rule::boolean()],
            'cooldown_minutes' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(60)],
            'message' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'buff_potion_ids.*.exists' => 'Only potions with a lasting effect can be kept active.',
            'rage_potion_ids.*.exists' => 'Only rage potions can be drunk to refill rage.',
        ];
    }

    /**
     * The lists to play, in order: `quest_list_ids`, or the legacy single id.
     *
     * @return list<int>
     */
    public function questListIds(): array
    {
        $ids = $this->validated('quest_list_ids') ?? array_filter([$this->validated('quest_list_id')]);

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * The hand-typed giver + game quest id are only needed when no catalog
     * quest was picked.
     */
    private function requiredForRawQuest(): RequiredIf
    {
        return Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::Quest->value
            && ! $this->filled('catalog_quest_id'));
    }
}
