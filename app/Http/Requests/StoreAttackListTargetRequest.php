<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttackListTargetRequest extends FormRequest
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
            // Targets are added by name — what the user knows. The player id
            // is resolved on the first search and cached back onto the row.
            'name' => ['required', 'string', 'max:255'],
            'player_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
