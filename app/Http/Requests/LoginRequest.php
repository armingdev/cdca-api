<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
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
            'email' => [Rule::required(), Rule::email()],
            'password' => [Rule::required(), Rule::string()],
            'device_name' => [Rule::sometimes(), Rule::string()->max(255)],
        ];
    }
}
