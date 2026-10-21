<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StaffReportIndexRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', \App\Models\FoundItem::class) ?? false; }
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'string', 'in:PENDING,RECEIVED,WITHDRAWN,CLOSED,OPEN,RESOLVED'],
            'category_id' => ['sometimes', 'integer', 'min:1', 'exists:category,category_id'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('from') && $this->filled('to') && $this->input('from') > $this->input('to')) $v->errors()->add('to', 'The end date must be on or after the start date.');
        });
    }
}