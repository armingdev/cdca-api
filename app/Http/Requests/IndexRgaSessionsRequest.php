<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRgaSessionsRequest extends FormRequest
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
            // Ask the game about each session before handing it out (one
            // request per RGA); dead ones are marked invalid and flagged.
            'verify' => [Rule::sometimes(), Rule::boolean()],
        ];
    }
}
