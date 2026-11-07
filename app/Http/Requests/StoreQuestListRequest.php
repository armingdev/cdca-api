<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestListRequest extends FormRequest
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
                Rule::required(), Rule::string()->max(255),
                Rule::unique('quest_lists', 'name')->where('user_id', $this->user()->id),
            ],
            'is_public' => [Rule::sometimes(), Rule::boolean()],
        ];
    }
}
