<?php

namespace App\Http\Requests\Items;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class ShowItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageItems', FoundItem::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
