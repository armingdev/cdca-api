<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Numeric;

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
            'search' => [Rule::sometimes(), Rule::string()->max(255)],
            'giver' => [Rule::sometimes(), Rule::string()->max(255)],
            'min_level' => [Rule::sometimes(), Rule::numeric()->integer()->min(0)],
            // gte:min_level fails outright when min_level is absent, which made
            // a max-only range a 422 — compare only when both are given.
            'max_level' => [
                Rule::sometimes(),
                Rule::numeric()->integer()->min(0)
                    ->when($this->filled('min_level'), fn (Numeric $rule) => $rule->greaterThanOrEqualTo('min_level')),
            ],
            'sort' => [Rule::sometimes(), Rule::in(['name', 'required_level', 'total_exp', 'giver', 'steps_count', 'game_quest_id'])],
            'dir' => [Rule::sometimes(), Rule::in(['asc', 'desc'])],
            'per_page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(100)],
            'page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
        ];
    }
}
