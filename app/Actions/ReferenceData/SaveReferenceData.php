<?php

namespace App\Actions\ReferenceData;

use App\Models\Category;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveReferenceData
{
    public function execute(Model $record, array $data): Model
    {
        // Only the existing models' approved editable attributes are written.
        $attributes = array_intersect_key($data, array_flip($record->getFillable()));

        try {
            return DB::transaction(function () use ($record, $attributes): Model {
                $current = $record->exists
                    ? $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail()
                    : $record;

                $current->fill($attributes)->save();

                return $current->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A database unique index remains authoritative if validation races.
            $field = match ($record::class) {
                Category::class => 'category_name',
                StorageLocation::class => 'location_code',
                default => throw $exception,
            };

            throw ValidationException::withMessages([
                $field => 'The '.$field.' has already been taken.',
            ]);
        }
    }
}
