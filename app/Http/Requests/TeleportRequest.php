<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeleportRequest extends FormRequest
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
            'anchor_id' => [Rule::sometimes(), Rule::numeric()->integer(), Rule::exists('teleport_anchors', 'id')],
            'room_id' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            'home_tavern' => [Rule::sometimes(), Rule::boolean()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('anchor_id') && ! $this->filled('room_id') && ! $this->boolean('home_tavern')) {
                $validator->errors()->add(
                    'room_id',
                    'Provide a room_id to travel to, an anchor_id to jump with, or set home_tavern.',
                );
            }
        });
    }
}
