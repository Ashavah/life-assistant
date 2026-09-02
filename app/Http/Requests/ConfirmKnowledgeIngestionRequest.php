<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmKnowledgeIngestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $ingestion = $this->route('knowledgeIngestion');

        return $this->user()?->can('confirm', $ingestion) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'selected_items' => ['present', 'array'],
            'selected_items.*' => [
                'integer',
                'distinct',
                Rule::exists('knowledge_ingestion_items', 'id')
                    ->where('knowledge_ingestion_id', $this->route('knowledgeIngestion')?->id),
            ],
        ];
    }
}
