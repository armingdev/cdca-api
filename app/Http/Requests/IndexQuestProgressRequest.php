<?php

namespace App\Http\Requests;

use App\Game\Enums\QuestProgressStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQuestProgressRequest extends FormRequest
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
            'per_page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)->max(100)],
            'page' => [Rule::sometimes(), Rule::numeric()->integer()->min(1)],
            'status' => [Rule::sometimes(), Rule::enum(QuestProgressStatus::class)],
        ];
    }
}
