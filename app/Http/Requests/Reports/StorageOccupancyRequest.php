<?php

namespace App\Http\Requests\Reports;

class StorageOccupancyRequest extends ReportIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'location_id' => ['sometimes', 'integer', 'exists:storage_location,location_id'],
        ]);
    }
}
