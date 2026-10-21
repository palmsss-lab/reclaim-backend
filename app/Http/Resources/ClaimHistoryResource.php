<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ClaimHistoryResource extends ClaimResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'has_proof_image' => (bool) $this->has_proof_image,
            'claimant' => [
                'person_id' => (int) $this->claimant_id,
                'full_name' => $this->claimant_name,
                'contact_details' => $this->claimant_contact,
            ],
            'reviewer' => $this->reviewed_by === null ? null
                : ['staff_id' => (int) $this->reviewed_by, 'full_name' => $this->reviewer_name],
        ]);
    }
}
