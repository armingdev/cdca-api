<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CastSkillRequest extends FormRequest
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
            'skill_id' => [Rule::sometimes(), Rule::numeric()->integer(), Rule::exists('skills', 'id')],
            'on_start' => [Rule::sometimes(), Rule::boolean()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('skill_id') && ! $this->boolean('on_start')) {
                $validator->errors()->add('skill_id', 'Provide a skill_id, or set on_start to cast the selected set.');
            }
        });
    }
}
