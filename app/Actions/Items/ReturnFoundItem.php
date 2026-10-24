<?php

namespace App\Actions\Items;

use App\Models\FoundItem;
use App\Services\InAppNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReturnFoundItem
{
    public function execute(int $itemId, int $claimantId, int $staffId, ?string $returnedAt = null): object
    {
        return DB::transaction(function () use ($itemId, $claimantId, $staffId, $returnedAt): object {
            // Same parent-first lock as submission, rejection, and transfer.
            $item = FoundItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if (DB::table('claim')->where('item_id', $itemId)->where('outcome', 'RETURNED')->exists()) {
                throw new ConflictHttpException('This item has already been returned.');
            }

            $claims = DB::table('claim')->where('item_id', $itemId)->where('claimant_id', $claimantId);
            $claim = $claims->first();
            if (! $claim) {
                throw new NotFoundHttpException('The claim was not found for this item.');
            }
            if ($claim->outcome !== 'PENDING') {
                throw new ConflictHttpException('An item can only be returned against a pending claim.');
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

            $pending = DB::table('claim')->where('item_id', $itemId)->where('outcome', 'PENDING')
                ->selectRaw('COUNT(*) AS claim_count, MAX(submitted_at) AS latest_submission')
                ->first();
            $timezone = config('app.timezone');
            $now = CarbonImmutable::now($timezone);
            $time = $returnedAt === null ? $now : CarbonImmutable::parse($returnedAt, $timezone)->setTimezone($timezone);

            if ($time->greaterThan($now)
                || $time->lessThan($item->received_at)
                || $time->lessThan(CarbonImmutable::parse($pending->latest_submission, $timezone))
                || $time->lessThanOrEqualTo(CarbonImmutable::parse($current->assigned_at, $timezone))) {
                throw ValidationException::withMessages([
                    'returned_at' => 'The return time must not be in the future, must be at or after receipt and every pending claim, and must be strictly after the current placement start.',
                ]);
            }

            $reviewFields = [
                'reviewed_by' => $staffId,
                'reviewed_at' => $time->format('Y-m-d H:i:s.u'),
            ];
            $outcomes = DB::table('claim')->where('item_id', $itemId)->where('outcome', 'PENDING')
                ->lockForUpdate()->pluck('claimant_id')->mapWithKeys(fn ($personId) => [(int) $personId => (int) $personId === $claimantId ? 'RETURNED' : 'REJECTED'])->all();
            $updated = (clone $claims)->where('outcome', 'PENDING')
                ->update(array_merge($reviewFields, ['outcome' => 'RETURNED']));
            if ($updated !== 1) {
                throw new ConflictHttpException('The selected claim changed during return.');
            }

            // The item scope and claimant condition preserve the composite-key boundary.
            $rejected = DB::table('claim')->where('item_id', $itemId)
                ->where('claimant_id', '!=', $claimantId)->where('outcome', 'PENDING')
                ->update(array_merge($reviewFields, ['outcome' => 'REJECTED']));
            if ($rejected !== (int) $pending->claim_count - 1) {
                throw new ConflictHttpException('The other pending claims changed during return.');
            }

            $closed = DB::table('storage_assignment')
                ->where('item_id', $itemId)
                ->where('assignment_no', $current->assignment_no)
                ->whereNull('removed_at')
                ->update(['removed_at' => $time->format('Y-m-d H:i:s.u')]);
            if ($closed !== 1) {
                throw new ConflictHttpException('The current storage assignment changed during return.');
            }

            app(InAppNotifications::class)->claimsReviewed($itemId, $outcomes);
            return $claims->first();
        });
    }
}
