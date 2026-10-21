<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinderReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void
    {
        foreach (['item_description', 'found_place'] as $key) if (is_string($this->input($key))) $this->merge([$key => trim($this->input($key))]);
    }
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];
        return [
            'item_description' => array_merge($required, ['string', 'max:16000', 'not_regex:/^\s*$/']),
            'found_place' => array_merge($required, ['string', 'max:200', 'not_regex:/^\s*$/']),
            'found_at' => array_merge($required, ['date', 'before_or_equal:now']),
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:category,category_id'],
            'user_id' => ['prohibited'], 'finder_id' => ['prohibited'], 'status' => ['prohibited'],
            'received_item_id' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        if ($this->isMethod('PATCH')) $validator->after(function (Validator $v): void { if ($this->validated() === []) $v->errors()->add('report', 'At least one report field must be supplied.'); });
    }
}