<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserClaimRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('evidence_text'))) $this->merge(['evidence_text' => trim($this->input('evidence_text'))]);
    }
    public function rules(): array
    {
        return [
            'evidence_text' => ['required', 'string', 'max:16000', 'not_regex:/^\s*$/'],
            'image' => ['nullable', 'file', 'max:5120'],
            'claimant_id' => ['prohibited'], 'person_id' => ['prohibited'], 'outcome' => ['prohibited'],
            'submitted_at' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'],
        ];
    }
}