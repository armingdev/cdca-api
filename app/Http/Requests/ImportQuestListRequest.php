<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportQuestListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Either an uploaded list file (a dDCT .dql, or a plain text file — the
     * extension is not what identifies it) or the same content pasted as text.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Read once as text and never stored, so the limits that matter
            // are size and "is it text at all".
            'file' => ['required_without:text', 'file', 'max:256', 'mimetypes:application/json,text/plain'],
            'text' => ['required_without:file', 'string', 'max:262144'],
            // Optional for a dDCT list, which carries its own name.
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'The file must be a text or JSON quest list.',
        ];
    }

    /** The list content, wherever it came from. */
    public function content(): string
    {
        return $this->hasFile('file')
            ? (string) $this->file('file')->get()
            : (string) $this->validated('text');
    }

    /** A name for a list whose content does not carry one: the uploaded file's own. */
    public function fallbackName(): ?string
    {
        return $this->hasFile('file')
            ? pathinfo($this->file('file')->getClientOriginalName(), PATHINFO_FILENAME)
            : null;
    }
}
