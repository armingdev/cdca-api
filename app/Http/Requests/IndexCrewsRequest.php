<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCrewsRequest extends FormRequest
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
            'search' => [Rule::sometimes(), Rule::string()->max(255)],
            'server_id' => [Rule::sometimes(), Rule::numeric()->integer(), Rule::in(array_keys(config('outwar.servers')))],
            'per_page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(100)],
            'page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
        ];
    }
}
