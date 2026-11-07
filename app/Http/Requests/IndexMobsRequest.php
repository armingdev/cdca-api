<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMobsRequest extends FormRequest
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
            'q' => [Rule::sometimes(), Rule::string()->max(255)],
            'per_page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(100)],
            'page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
        ];
    }
}
