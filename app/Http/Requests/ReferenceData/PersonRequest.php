<?php

namespace App\Http\Requests\ReferenceData;

use App\Models\Person;

class PersonRequest extends ReferenceDataRequest
{
    protected function modelClass(): string
    {
        return Person::class;
    }

    protected function writeRules(): array
    {
        return [
            'full_name' => array_merge($this->requiredField(), ['string', 'max:120', 'not_regex:/^\s*$/']),
            'contact_details' => array_merge($this->requiredField(), ['string', 'max:200', 'not_regex:/^\s*$/']),
        ];
    }
}
