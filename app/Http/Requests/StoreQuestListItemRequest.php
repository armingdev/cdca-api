<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestListItemRequest extends FormRequest
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
            'quest_id' => ['required', 'integer', 'min:1'],
            'npc_name' => ['required', 'string', 'max:255'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
