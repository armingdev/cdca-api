<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCharacterSkillsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A sync costs five throttled game reads, so the client selecting a
     * character can pass `max_age_seconds` to make the call free when the
     * stored state is recent enough, and `with_recharge` to pay for the
     * per-skill recharge windows only when it actually renders them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_age_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'with_recharge' => ['sometimes', 'boolean'],
        ];
    }
}
