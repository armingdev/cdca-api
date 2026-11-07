<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The member's full share set for one crew group — replaces what they shared
 * before. Only their own RGAs can be listed; `[]` withdraws everything.
 */
class UpdateCrewGroupSharedRgasRequest extends FormRequest
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
            'rga_ids' => [Rule::present(), Rule::array()->max(75)],
            'rga_ids.*' => [
                Rule::numeric()->integer(),
                Rule::distinct(),
                Rule::exists('rgas', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rga_ids.*.exists' => 'You can only share your own game accounts.',
        ];
    }

    /**
     * @return list<int>
     */
    public function rgaIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('rga_ids')));
    }
}
