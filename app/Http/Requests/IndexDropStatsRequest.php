<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexDropStatsRequest extends FormRequest
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
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'character_id' => ['sometimes', 'integer', 'min:1'],
            'mob_id' => ['sometimes', 'integer', 'min:1'],
            'run_id' => ['sometimes', 'integer', 'min:1'],
            // drop = one row per item; mob = one row per item and the mob that dropped it.
            'group_by' => ['sometimes', 'in:drop,mob'],
        ];
    }
}
