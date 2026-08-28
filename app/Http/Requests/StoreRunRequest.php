<?php

namespace App\Http\Requests;

use App\Game\Enums\RunMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(RunMode::class)],
            'characters' => ['required', 'array', 'min:1'],
            'characters.*' => ['integer', 'exists:characters,id'],

            'cast_on_start' => ['sometimes', 'boolean'],
            'require_circumspect' => ['sometimes', 'boolean'],
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

            // quest mode
            'npc' => ['required_if:mode,quest', 'string', 'max:255'],
            'quest_id' => ['required_if:mode,quest', 'integer', 'min:1'],

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
            'crew_game_id' => ['required_if:mode,pvp-crew-members', 'integer', 'min:1'],

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
}
