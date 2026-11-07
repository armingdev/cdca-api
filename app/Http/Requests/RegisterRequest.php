<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => [Rule::required(), Rule::string()->max(255)],
            'email' => [Rule::required(), Rule::email(), Rule::string()->max(255), Rule::unique('users', 'email')],
            'password' => [Rule::required(), Rule::confirmed(), Password::defaults()],
            'device_name' => [Rule::sometimes(), Rule::string()->max(255)],
        ];
    }
}
