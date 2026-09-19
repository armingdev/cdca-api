<?php

namespace App\Http\Requests;

use App\Game\Enums\RageReserveEvent;
use App\Game\Enums\RunMode;
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
            'mode' => ['required', Rule::enum(RunMode::class)],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'characters' => ['required', 'array', 'min:1'],
            'characters.*' => ['integer', 'exists:characters,id'],

            'cast_on_start' => ['sometimes', 'boolean'],

            // The fleet-wide selection: replaces every listed character's
            // cast-on-start set. Omitted = each keeps its own. The array-level
            // exists rule checks the whole list in one query.
            'skill_ids' => ['sometimes', 'array', Rule::exists('skills', 'id')],
            'skill_ids.*' => ['integer'],

            'require_circumspect' => ['sometimes', 'boolean'],

            // Park ahead of these events so the rage bar is full for them.
            'reserve_rage_for' => ['sometimes', 'array'],
            'reserve_rage_for.*' => [Rule::enum(RageReserveEvent::class), 'distinct'],
            'reserve_rage_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'restart_every_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_at' => ['sometimes', 'nullable', 'date'],
            'stop_rage' => ['sometimes', 'integer', 'min:0'],
            'level_up' => ['sometimes', 'boolean'],
            'smart' => ['sometimes', 'boolean'],

            // mob mode
            'mobs' => ['required_if:mode,mob', 'array'],
            'mobs.*' => ['string', 'max:255'],
            'max_kills' => ['sometimes', 'integer', 'min:0'],
            'drop_junk' => ['sometimes', 'boolean'],
            'run_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'attack_interval_seconds' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],

            // quest mode — either a quest picked from the catalog (its giver
            // and game id come from the catalog row), or the raw pair for a
            // quest the catalog does not know yet.
            'catalog_quest_id' => ['sometimes', 'nullable', 'integer', 'exists:quests,id'],
            'npc' => [$this->requiredForRawQuest(), 'string', 'max:255'],
            'quest_id' => [$this->requiredForRawQuest(), 'integer', 'min:1'],

            // quest + quest-list: pause before re-checking rooms whose targets were all dead
            'respawn_wait_seconds' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],

            // quest + quest-list: skip steps wanting an item the game only sells
            'skip_shard_quests' => ['sometimes', 'boolean'],

            // quest-list mode
            'quest_list_id' => ['required_if:mode,quest-list', 'integer', 'exists:quest_lists,id'],

            // pvp — attack-list mode takes either a saved list or inline names
            'attack_list_id' => ['sometimes', 'nullable', 'integer', 'exists:attack_lists,id'],
            'targets' => [
                'array',
                // Inline names are required only when no saved list is given.
                Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::PvpAttackList->value
                    && ! $this->filled('attack_list_id')),
            ],
            'targets.*' => ['string', 'max:255'],

            // pvp — crew-members mode
            // One run can work through several crews' rosters. The single
            // crew_game_id is still accepted from clients that predate that.
            'crew_game_ids' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === RunMode::PvpCrewMembers->value && ! $this->filled('crew_game_id')),
                'array', 'min:1', 'max:10',
            ],
            'crew_game_ids.*' => ['integer', 'min:1', 'distinct'],
            'crew_game_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // pvp — shared options.
            // No attack_rage: the rage cost is supplied by the server per
            // target (VERIFIED 2026-08-22), so a client value would be wrong.
            'attacks_per_target' => ['sometimes', 'integer', 'min:1'],
            'max_attacks' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'skip_too_strong' => ['sometimes', 'boolean'],
            'auto_enter_brawl' => ['sometimes', 'boolean'],
            'cooldown_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
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
