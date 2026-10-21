<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_account_admin' => (bool) $this->is_account_admin,
            'is_active' => $this->deactivated_at === null,
            'can_manage' => $request->user()?->can('modifyAccount', $this->resource) ?? false,
            'staff' => $this->staff === null ? null : [
                'staff_id' => (int) $this->staff->staff_id,
                'full_name' => $this->staff->full_name,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
