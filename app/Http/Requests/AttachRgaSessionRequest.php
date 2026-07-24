<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachRgaSessionRequest extends FormRequest
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
            'rg_sess_id' => ['required', 'string', 'regex:/^[0-9a-fA-F]{32}$/'],
            'token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cuserid2' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
