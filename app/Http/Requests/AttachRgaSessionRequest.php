<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'rg_sess_id' => [Rule::required(), Rule::string(), Rule::regex('/^[0-9a-fA-F]{32}$/')],
            'token' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
            'cuserid2' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
        ];
    }
}
