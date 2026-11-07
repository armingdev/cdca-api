<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'name' => [Rule::required(), Rule::string()->max(255)],
            'player_id' => [Rule::sometimes(), Rule::nullable(), Rule::numeric()->integer()->min(1)],
        ];
    }
}
