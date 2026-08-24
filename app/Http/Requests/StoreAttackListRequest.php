<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttackListRequest extends FormRequest
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
            // Scoped to the owner, so two users may both have a "Rivals" list.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('attack_lists', 'name')->where('user_id', $this->user()->id),
            ],
            'server_id' => ['sometimes', 'nullable', 'integer', 'in:1,2'],
        ];
    }
}
