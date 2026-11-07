<?php

namespace App\Http\Requests;

use App\Game\Enums\PotionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPotionsRequest extends FormRequest
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
            'kind' => [Rule::sometimes(), Rule::enum(PotionKind::class)],
            'search' => [Rule::sometimes(), Rule::string()->max(100)],
        ];
    }
}
