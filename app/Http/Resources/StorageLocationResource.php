<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'location_id' => (int) $this->location_id,
            'location_code' => $this->location_code,
            'location_description' => $this->location_description,
        ];
    }
}
