<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveFinderReportRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', \App\Models\FoundItem::class) ?? false; }
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'min:1', 'exists:category,category_id'],
            'initial_location_id' => ['required', 'integer', 'min:1', 'exists:storage_location,location_id'],
            'item_description' => ['prohibited'], 'finder_id' => ['prohibited'], 'received_by' => ['prohibited'],
            'assigned_by' => ['prohibited'], 'received_item_id' => ['prohibited'],
        ];
    }
}