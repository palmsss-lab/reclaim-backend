<?php

namespace App\Http\Requests\Items;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublicItemIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:200'],
            'category_id' => ['sometimes', 'integer', 'min:1', 'exists:category,category_id'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('received_from') && $this->filled('received_to') && $this->input('received_from') > $this->input('received_to')) {
                $validator->errors()->add('received_to', 'The end date must be on or after the start date.');
            }
        });
    }
}