<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Foundation\Http\FormRequest;

abstract class ReferenceDataRequest extends FormRequest
{
    abstract protected function modelClass(): string;

    abstract protected function writeRules(): array;

    public function authorize(): bool
    {
        return $this->user()?->can('manageReferenceData', $this->modelClass()) ?? false;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET') || $this->isMethod('HEAD')) {
            return [
                'q' => ['sometimes', 'nullable', 'string', 'max:200'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }

        return array_merge($this->writeRules(), [
            'person_id' => ['prohibited'],
            'category_id' => ['prohibited'],
            'location_id' => ['prohibited'],
        ]);
    }

    protected function requiredField(): array
    {
        return $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];
    }

    public function searchPattern(): ?string
    {
        $search = $this->validated('q');
        if ($search === null || $search === '') {
            return null;
        }

        // Treat %, _, and the escape character literally in substring searches.
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
