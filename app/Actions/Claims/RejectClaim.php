<?php

namespace App\Actions\Claims;

use App\Models\FoundItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RejectClaim
{
    public function execute(int $itemId, int $claimantId, int $staffId, ?string $reviewedAt = null): object
    {
        return DB::transaction(function () use ($itemId, $claimantId, $staffId, $reviewedAt): object {
            $item = FoundItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $claims = DB::table('claim')->where('item_id', $itemId)->where('claimant_id', $claimantId);
            $claim = $claims->first();

            if (! $claim) {
                throw new NotFoundHttpException('The claim was not found for this item.');
            }
            if ($claim->outcome !== 'PENDING') {
                throw new ConflictHttpException('Only a pending claim can be rejected.');
            }

            $timezone = config('app.timezone');
            $now = CarbonImmutable::now($timezone);
            $time = $reviewedAt === null ? $now : CarbonImmutable::parse($reviewedAt, $timezone)->setTimezone($timezone);
            if ($time->greaterThan($now)
                || $time->lessThan($item->received_at)
                || $time->lessThan(CarbonImmutable::parse($claim->submitted_at, $timezone))) {
                throw ValidationException::withMessages([
                    'reviewed_at' => 'The review time must not be in the future or earlier than receipt or claim submission.',
                ]);
            }

            $updated = (clone $claims)->where('outcome', 'PENDING')->update([
                'outcome' => 'REJECTED',
                'reviewed_by' => $staffId,
                'reviewed_at' => $time->format('Y-m-d H:i:s.u'),
            ]);
            if ($updated !== 1) {
                throw new ConflictHttpException('The claim changed during review.');
            }

            return $claims->first();
        });
    }
}
