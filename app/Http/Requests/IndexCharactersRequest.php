<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Numeric;

class IndexCharactersRequest extends FormRequest
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
            'server_id' => [Rule::sometimes(), Rule::numeric()->integer(), Rule::in(array_keys(config('outwar.servers')))],
            'rga_id' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            // own = the RGA's own characters, trustee = shared by another RGA.
            'ownership' => [Rule::sometimes(), Rule::in(['own', 'trustee'])],
            // Inclusive level range, e.g. 40–60 to select a band of the fleet.
            'min_level' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(95)],
            'max_level' => [
                Rule::sometimes(),
                Rule::numeric()->integer()->min(1)->max(95)
                    ->when($this->filled('min_level'), fn (Numeric $rule) => $rule->greaterThanOrEqualTo('min_level')),
            ],
        ];
    }
}
