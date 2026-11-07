<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCharacterSkillsRequest extends FormRequest
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
            'skill_ids' => [Rule::present(), Rule::array()],
            'skill_ids.*' => [Rule::numeric()->integer(), Rule::exists('skills', 'id')],
        ];
    }
}
