<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $returned = $this->returned_claimant_id !== null;
        $stored = ! $returned && $this->assignment_no !== null;

        return [
            'item_id' => (int) $this->item_id,
            'finder_id' => (int) $this->finder_id,
            'received_by' => (int) $this->received_by,
            'category_id' => (int) $this->category_id,
            'item_description' => $this->item_description,
            'found_place' => $this->found_place,
            'found_at' => $this->found_at->format('Y-m-d\TH:i:s.uP'),
            'received_at' => $this->received_at->format('Y-m-d\TH:i:s.uP'),
            'finder' => [
                'person_id' => (int) $this->finder_id,
                'full_name' => $this->finder_name,
                'contact_details' => $this->finder_contact,
            ],
            'category' => ['category_id' => (int) $this->category_id, 'category_name' => $this->category_name],
            'receiving_staff' => ['staff_id' => (int) $this->received_by, 'full_name' => $this->receiver_name],
            'is_returned' => $returned,
            'current_location' => $stored ? [
                'location_id' => (int) $this->location_id,
                'location_code' => $this->location_code,
                'location_description' => $this->location_description,
            ] : null,
            'current_assignment' => $stored ? [
                'assignment_no' => (int) $this->assignment_no,
                'assigned_by' => (int) $this->assigned_by,
                'assigned_at' => $this->date($this->assigned_at),
                'assigning_staff' => ['staff_id' => (int) $this->assigned_by, 'full_name' => $this->assigner_name],
            ] : null,
            'return' => $returned ? [
                'claimant_id' => (int) $this->returned_claimant_id,
                'reviewed_by' => (int) $this->reviewed_by,
                'reviewed_at' => $this->date($this->reviewed_at),
                'claimant' => [
                    'person_id' => (int) $this->returned_claimant_id,
                    'full_name' => $this->claimant_name,
                    'contact_details' => $this->claimant_contact,
                ],
                'reviewer' => ['staff_id' => (int) $this->reviewed_by, 'full_name' => $this->reviewer_name],
            ] : null,
        ];
    }

    private function date(string $value): string
    {
        return CarbonImmutable::parse($value, config('app.timezone'))->format('Y-m-d\TH:i:s.uP');
    }
}
