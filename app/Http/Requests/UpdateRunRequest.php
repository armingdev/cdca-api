<?php

namespace App\Http\Requests;

use App\Game\Enums\RunPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The label and the priority are editable; everything else about a run is
     * what its workers are already acting on. A PATCH must carry at least the
     * name (null clears it) unless it changes the priority.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [Rule::when(! $this->has('priority'), ['present']), 'nullable', 'string', 'max:80'],
            'priority' => ['sometimes', Rule::enum(RunPriority::class)],
        ];
    }
}
