<?php

namespace App\Http\Requests\ReferenceData;

use App\Models\Category;
use Illuminate\Validation\Rule;

class CategoryRequest extends ReferenceDataRequest
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function writeRules(): array
    {
        $unique = Rule::unique('category', 'category_name');
        if ($this->route('category') instanceof Category) {
            $unique->ignore($this->route('category'));
        }

        return [
            'category_name' => array_merge($this->requiredField(), ['string', 'max:60', 'not_regex:/^\s*$/', $unique]),
        ];
    }
}
