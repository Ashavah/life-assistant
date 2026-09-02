<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeIngestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $character = $this->route('character');

        return $this->user() !== null
            && $character !== null
            && $character->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => [
                'nullable',
                'string',
                'max:'.config('knowledge.max_text_characters', 200000),
                'required_without:files',
            ],
            'files' => [
                'nullable',
                'array',
                'max:'.config('knowledge.max_files', 10),
                'required_without:text',
            ],
            'files.*' => [
                'file',
                'max:'.config('knowledge.max_file_kilobytes', 20480),
                'mimes:txt,md,pdf,docx,jpg,jpeg,png,webp',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required_without' => 'Incolla del testo oppure seleziona almeno un file.',
            'files.required_without' => 'Incolla del testo oppure seleziona almeno un file.',
            'files.*.mimes' => 'Sono supportati TXT, Markdown, PDF, DOCX, JPG, PNG e WebP.',
            'files.*.max' => 'Ogni file può pesare al massimo 20 MB.',
        ];
    }
}
