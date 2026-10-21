<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'person_id' => (int) $this->person_id,
            'full_name' => $this->full_name,
            'contact_details' => $this->contact_details,
        ];
    }
}
