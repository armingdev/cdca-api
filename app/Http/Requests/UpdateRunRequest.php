<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the label is editable: everything else about a run is what its
     * workers are already acting on.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['present', 'nullable', 'string', 'max:80'],
        ];
    }
}
