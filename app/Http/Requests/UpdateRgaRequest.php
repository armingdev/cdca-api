<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update an RGA's credentials. The username is deliberately immutable —
 * it is the account's identity (globally unique); a different account is
 * a new RGA.
 */
class UpdateRgaRequest extends FormRequest
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
            'password' => ['sometimes', 'string'],
            'security_answer' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
