<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetHomeTavernRequest extends FormRequest
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
            'room_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
