<?php

namespace App\Http\Requests;

use App\Game\Enums\RunEventType;
use App\Models\RunEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRunEventsRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'participant_id' => ['sometimes', 'integer', 'min:1'],
            'character_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(RunEventType::class)],
            'level' => ['sometimes', Rule::in([RunEvent::LEVEL_INFO, RunEvent::LEVEL_WARNING, RunEvent::LEVEL_ERROR])],
            // Live tailing: only rows newer than the last one the client holds.
            'after_id' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
