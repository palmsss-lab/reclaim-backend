<?php

namespace App\Http\Requests\ReferenceData;

use App\Models\StorageLocation;
use Illuminate\Validation\Rule;

class StorageLocationRequest extends ReferenceDataRequest
{
    protected function modelClass(): string
    {
        return StorageLocation::class;
    }

    protected function writeRules(): array
    {
        $unique = Rule::unique('storage_location', 'location_code');
        if ($this->route('location') instanceof StorageLocation) {
            $unique->ignore($this->route('location'));
        }

        return [
            'location_code' => array_merge($this->requiredField(), ['string', 'max:30', 'not_regex:/^\s*$/', $unique]),
            'location_description' => array_merge($this->requiredField(), ['string', 'max:200', 'not_regex:/^\s*$/']),
        ];
    }
}
