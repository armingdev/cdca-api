<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'quest_id' => [Rule::required(), Rule::numeric()->integer(), Rule::exists('quests', 'id')],
            'label' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
        ];
    }
}
