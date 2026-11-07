<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'from' => [Rule::sometimes(), Rule::date()],
            'to' => [Rule::sometimes(), Rule::date()->afterOrEqual('from')],
            'character_id' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            'mob_id' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            'run_id' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            // drop = one row per item; mob = one row per item and the mob that dropped it.
            'group_by' => [Rule::sometimes(), Rule::in(['drop', 'mob'])],
        ];
    }
}
