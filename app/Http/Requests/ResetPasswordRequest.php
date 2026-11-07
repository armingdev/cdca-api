<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            'token' => [Rule::required(), Rule::string()],
            'email' => [Rule::required(), Rule::email(), Rule::string()->max(255)],
            'password' => [Rule::required(), Rule::confirmed(), Password::defaults()],
        ];
    }
}
