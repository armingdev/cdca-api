<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexQuestsRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:255'],
            'giver' => ['sometimes', 'string', 'max:255'],
            'min_level' => ['sometimes', 'integer', 'min:0'],
            'max_level' => ['sometimes', 'integer', 'min:0', 'gte:min_level'],
            'sort' => ['sometimes', 'in:name,required_level,total_exp,giver,steps_count,game_quest_id'],
            'dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
