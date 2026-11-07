<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an RGA's credentials and settings. The username is deliberately immutable —
 * it is the account's identity (globally unique); a different account is
 * a new RGA.
 */
class UpdateRgaRequest extends FormRequest
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
            'password' => [Rule::sometimes(), Rule::string()],
            'security_answer' => [Rule::sometimes(), Rule::nullable(), Rule::string()->max(255)],
            // Let the scheduler claim the daily challenge tokens (on by default).
            'auto_claim_tokens' => [Rule::sometimes(), Rule::boolean()],
        ];
    }
}
