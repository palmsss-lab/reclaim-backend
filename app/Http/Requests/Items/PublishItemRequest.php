<?php

namespace App\Http\Requests\Items;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class PublishItemRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', FoundItem::class) ?? false; }
    public function rules(): array
    {
        return [
            'public_description' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
            'item_description' => ['prohibited'], 'found_place' => ['prohibited'], 'finder_id' => ['prohibited'],
            'received_by' => ['prohibited'], 'location_id' => ['prohibited'], 'storage_location' => ['prohibited'],
            'evidence_text' => ['prohibited'], 'contact_details' => ['prohibited'],
        ];
    }
}