<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = config('app.timezone');

        return [
            'item_id' => (int) $this->item_id,
            'claimant_id' => (int) $this->claimant_id,
            'submitted_at' => CarbonImmutable::parse($this->submitted_at, $timezone)->format('Y-m-d\TH:i:s.uP'),
            'evidence_text' => $this->evidence_text,
            'outcome' => $this->outcome,
            'reviewed_by' => $this->reviewed_by === null ? null : (int) $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at === null
                ? null
                : CarbonImmutable::parse($this->reviewed_at, $timezone)->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
