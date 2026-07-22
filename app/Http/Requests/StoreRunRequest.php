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

            // mob mode
            'mobs' => ['required_if:mode,mob', 'array'],
            'mobs.*' => ['string', 'max:255'],
            'max_kills' => ['sometimes', 'integer', 'min:0'],

            // quest mode
            'npc' => ['required_if:mode,quest', 'string', 'max:255'],
            'quest_id' => ['required_if:mode,quest', 'integer', 'min:1'],

            // quest-list mode
            'quest_list_id' => ['required_if:mode,quest-list', 'integer', 'exists:quest_lists,id'],

            // pvp mode
            'targets' => ['required_if:mode,pvp', 'array'],
            'targets.*' => ['string', 'max:255'],
            'attack_rage' => ['sometimes', 'integer', 'between:2,50'],
            'attacks_per_target' => ['sometimes', 'integer', 'min:1'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
