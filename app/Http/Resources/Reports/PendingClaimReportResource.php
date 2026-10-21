<?php

namespace App\Http\Resources\Reports;

use App\Http\Resources\ClaimHistoryResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PendingClaimReportResource extends ClaimHistoryResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'item' => [
                'item_id' => (int) $this->item_id,
                'item_description' => $this->item_description,
                'received_at' => CarbonImmutable::parse($this->item_received_at, config('app.timezone'))->format('Y-m-d\TH:i:s.uP'),
                'category' => [
                    'category_id' => (int) $this->item_category_id,
                    'category_name' => $this->item_category_name,
                ],
            ],
        ]);
    }
}
