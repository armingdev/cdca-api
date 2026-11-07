<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCrewGroupSessionsRequest extends FormRequest
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
            // Ask the game whether each shared session still works (one request per RGA).
            'verify' => ['sometimes', 'boolean'],
        ];
    }
}
