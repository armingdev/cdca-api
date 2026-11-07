<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
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
            // The API authenticates with Sanctum tokens, not the web session guard.
            'current_password' => [Rule::required(), Rule::string(), Rule::currentPassword()->guard('sanctum')],
            'password' => [Rule::required(), Rule::confirmed(), 'different:current_password', Password::defaults()],
        ];
    }
}
