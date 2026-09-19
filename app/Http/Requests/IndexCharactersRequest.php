<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCharactersRequest extends FormRequest
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
            'server_id' => ['sometimes', 'integer', Rule::in(array_keys(config('outwar.servers')))],
            'rga_id' => ['sometimes', 'integer', 'min:1'],
            // own = the RGA's own characters, trustee = shared by another RGA.
            'ownership' => ['sometimes', 'in:own,trustee'],
        ];
    }
}
