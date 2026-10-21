<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = config('app.timezone');

        return [
            'item_id' => (int) $this->item_id,
            'assignment_no' => (int) $this->assignment_no,
            'location_id' => (int) $this->location_id,
            'assigned_by' => (int) $this->assigned_by,
            'assigned_at' => CarbonImmutable::parse($this->assigned_at, $timezone)->format('Y-m-d\TH:i:s.uP'),
            'removed_at' => $this->removed_at === null ? null
                : CarbonImmutable::parse($this->removed_at, $timezone)->format('Y-m-d\TH:i:s.uP'),
            'location' => [
                'location_id' => (int) $this->location_id,
                'location_code' => $this->location_code,
                'location_description' => $this->location_description,
            ],
            'assigning_staff' => ['staff_id' => (int) $this->assigned_by, 'full_name' => $this->assigner_name],
        ];
    }
}
