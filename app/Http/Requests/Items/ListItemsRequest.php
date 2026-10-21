<?php

namespace App\Http\Requests\Items;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

class ListItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageItems', FoundItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_id' => ['sometimes', 'integer', 'exists:category,category_id'],
            'location_id' => ['sometimes', 'integer', 'exists:storage_location,location_id'],
            'location_scope' => ['sometimes', Rule::in(['current', 'history'])],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['stored', 'returned', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort_by' => [
                'required_with:sort_direction',
                Rule::in(['received_at', 'found_at', 'item_id', 'description', 'category', 'location']),
            ],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function withValidator(ValidationValidator $validator): void
    {
        $validator->after(function (ValidationValidator $validator): void {
            if ($validator->errors()->has('received_from') || $validator->errors()->has('received_to')) {
                return;
            }

            $from = $this->input('received_from');
            $to = $this->input('received_to');

            if (is_string($from) && is_string($to) && $to < $from) {
                $validator->errors()->add('received_to', 'The received to date must be on or after the received from date.');
            }
        });
    }
}
