<?php

namespace App\Actions\Items;

use App\Models\FoundItem;
use App\Models\StorageLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransferFoundItem
{
    public function execute(int $itemId, int $destinationId, int $staffId): FoundItem
    {
        return DB::transaction(function () use ($itemId, $destinationId, $staffId): FoundItem {
            $item = FoundItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if (DB::table('claim')->where('item_id', $itemId)->where('outcome', 'RETURNED')->exists()) {
                throw new ConflictHttpException('A returned item cannot be transferred.');
            }

            $openAssignments = DB::table('storage_assignment')
                ->where('item_id', $itemId)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->get();

            if ($openAssignments->count() !== 1) {
                throw new ConflictHttpException('The item must have exactly one open storage assignment.');
            }

            $current = $openAssignments->first();
            if ((int) $current->location_id === $destinationId) {
                throw ValidationException::withMessages([
                    'location_id' => 'Choose a different storage location.',
                ]);
            }

            if (! StorageLocation::query()->whereKey($destinationId)->exists()) {
                throw ValidationException::withMessages([
                    'location_id' => 'The selected storage location no longer exists.',
                ]);
            }

            $timezone = config('app.timezone');
            $transferredAt = CarbonImmutable::now($timezone);
            $currentStartedAt = CarbonImmutable::parse($current->assigned_at, $timezone);

            if ($transferredAt->lessThanOrEqualTo($currentStartedAt) || $transferredAt->lessThan($item->received_at)) {
                throw new ConflictHttpException('The transfer time must be later than the current placement start and no earlier than receipt.');
            }

            $closed = DB::table('storage_assignment')
                ->where('item_id', $itemId)
                ->where('assignment_no', $current->assignment_no)
                ->whereNull('removed_at')
                ->update(['removed_at' => $transferredAt->format('Y-m-d H:i:s.u')]);

            if ($closed !== 1) {
                throw new ConflictHttpException('The current storage assignment changed during transfer.');
            }

            $nextAssignmentNo = (int) DB::table('storage_assignment')
                ->where('item_id', $itemId)
                ->max('assignment_no') + 1;

            DB::table('storage_assignment')->insert([
                'item_id' => $itemId,
                'assignment_no' => $nextAssignmentNo,
                'location_id' => $destinationId,
                'assigned_by' => $staffId,
                'assigned_at' => $transferredAt->format('Y-m-d H:i:s.u'),
                'removed_at' => null,
            ]);

            return $item->load('category');
        });
    }
}
