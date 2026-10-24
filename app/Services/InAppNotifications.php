<?php

namespace App\Services;

use App\Models\FoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InAppNotifications
{
    public function possibleMatch(int $reportId, int $itemId): void
    {
        DB::transaction(function () use ($reportId, $itemId): void {
            FoundItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $report = DB::table('missing_item_report')->where('report_id', $reportId)->lockForUpdate()->first();
            abort_unless($report, 404);
            if ($report->status !== 'OPEN') throw new ConflictHttpException('Only open missing-item reports can receive possible matches.');
            // Locking reads see current state after waiting for an item transaction on TiDB.
            $listing = DB::table('public_item_listing')->where('item_id', $itemId)->lockForUpdate()->first();
            $placement = DB::table('storage_assignment')->where('item_id', $itemId)->whereNull('removed_at')->lockForUpdate()->first();
            $returned = DB::table('claim')->where('item_id', $itemId)->where('outcome', 'RETURNED')->lockForUpdate()->first();
            if (! $listing || $listing->unpublished_at !== null || ! $placement || $returned) {
                throw new ConflictHttpException('A possible match must be a currently published, stored item.');
            }
            $this->record((int) $report->user_id, "possible_match:$reportId:$itemId", 'possible_match', 'missing_report', $reportId,
                'Staff identified a possible match for your missing-item report. This does not confirm ownership or promise a return.', $itemId);
        });
    }

    public function finderChanged(int $reportId, int $userId, string $status): void
    {
        $message = $status === 'RECEIVED' ? 'Staff received the item from your found-item report.' : 'Staff closed your found-item report.';
        $this->record($userId, "finder:$reportId:$status", 'finder_'.strtolower($status), 'finder_report', $reportId, $message);
    }

    public function missingReviewed(int $reportId, int $userId): void
    {
        $this->record($userId, "missing:$reportId:reviewed", 'missing_reviewed', 'missing_report', $reportId,
            'Staff reviewed your missing-item report. Its status has not been changed.');
    }

    public function claimsReviewed(int $itemId, array $outcomes): void
    {
        $accounts = User::query()->whereIn('person_id', array_keys($outcomes))->whereNull('staff_id')->get(['id', 'person_id']);
        foreach ($accounts as $account) {
            $outcome = $outcomes[$account->person_id];
            $message = $outcome === 'RETURNED' ? "Staff recorded item #$itemId as returned to you." : "Staff reviewed your claim for item #$itemId. Your claim was not accepted.";
            $this->record((int) $account->id, "claim:$itemId:$account->person_id:$outcome", 'claim_'.strtolower($outcome), 'claim', $itemId, $message);
        }
    }

    private function record(int $userId, string $key, string $kind, string $targetType, int $targetId, string $message, ?int $itemId = null): void
    {
        // Retry deduplication must preserve the original creation/read times, including after reading.
        DB::table('in_app_notification')->upsert([
            'user_id' => $userId, 'event_key' => $key, 'kind' => $kind, 'target_type' => $targetType,
            'target_id' => $targetId, 'item_id' => $itemId, 'message' => $message, 'read_at' => null,
            'created_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u'),
        ], ['user_id', 'event_key'], ['event_key']);
    }
}
