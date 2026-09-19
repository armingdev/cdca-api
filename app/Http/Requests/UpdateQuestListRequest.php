<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestListRequest extends FormRequest
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
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('quest_lists', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('questList')),
            ],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
