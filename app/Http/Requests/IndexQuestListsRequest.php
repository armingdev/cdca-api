<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQuestListsRequest extends FormRequest
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
            // mine (default) = the user's own lists; community = lists other
            // users published plus the built-in ones, to read and copy.
            'scope' => [Rule::sometimes(), Rule::in(['mine', 'community'])],
        ];
    }
}
