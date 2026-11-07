<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRgaRequest extends FormRequest
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
            'username' => [Rule::required(), Rule::string()->max(255), Rule::unique('rgas', 'username')],
            'password' => [Rule::required(), Rule::string()],
            'security_answer' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
        ];
    }
}
