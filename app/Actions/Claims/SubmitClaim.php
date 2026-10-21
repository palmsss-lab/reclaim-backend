<?php

namespace App\Actions\Claims;

use App\Models\FoundItem;
use App\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SubmitClaim
{
    /** @param array{claimant_id: int, evidence_text: string} $data */
    public function execute(int $itemId, array $data): object
    {
        return DB::transaction(function () use ($itemId, $data): object {
            // All item workflows lock the parent first, including transfers.
            $item = FoundItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if (DB::table('claim')->where('item_id', $itemId)->where('outcome', 'RETURNED')->exists()) {
                throw new ConflictHttpException('A returned item cannot receive new claims.');
            }

            $claimantId = (int) $data['claimant_id'];
            $claims = DB::table('claim')->where('item_id', $itemId)->where('claimant_id', $claimantId);
            if ($claims->exists()) {
                throw new ConflictHttpException('This person already has a claim for this item.');
            }

            // Recheck after obtaining the parent lock; prevent claimant deletion until commit.
            if (! Person::query()->whereKey($claimantId)->sharedLock()->exists()) {
                throw ValidationException::withMessages([
                    'claimant_id' => 'The selected claimant no longer exists.',
                ]);
            }

            $evidence = trim($data['evidence_text']);
            if ($evidence === '' || preg_match('/^\s*$/u', $evidence)) {
                throw ValidationException::withMessages([
                    'evidence_text' => 'Ownership evidence must not be blank.',
                ]);
            }

            $submittedAt = CarbonImmutable::now(config('app.timezone'));
            if ($submittedAt->lessThan($item->received_at)) {
                throw new ConflictHttpException('The submission time cannot be earlier than the receipt time.');
            }

            DB::table('claim')->insert([
                'item_id' => $itemId,
                'claimant_id' => $claimantId,
                'submitted_at' => $submittedAt->format('Y-m-d H:i:s.u'),
                'evidence_text' => $evidence,
                'outcome' => 'PENDING',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            return $claims->first();
        });
    }
}
