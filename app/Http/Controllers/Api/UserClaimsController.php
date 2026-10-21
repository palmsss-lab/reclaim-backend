<?php

namespace App\Http\Controllers\Api;

use App\Actions\Claims\SubmitClaim;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\MyReportIndexRequest;
use App\Http\Requests\User\StoreUserClaimRequest;
use App\Models\FoundItem;
use App\Services\ImageFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserClaimsController extends Controller
{
    public function index(MyReportIndexRequest $request): JsonResponse
    {
        $personId = (int) $request->user()->person_id;
        $rows = $this->query($personId)->orderByDesc('c.submitted_at')->orderByDesc('c.item_id')
            ->paginate($request->validated('per_page', 20))->withQueryString();

        return response()->json($rows);
    }

    public function show(MyReportIndexRequest $request, int $item): JsonResponse
    {
        $row = $this->query((int) $request->user()->person_id)->where('c.item_id', $item)->first();
        abort_unless($row, 404);

        return response()->json(['data' => $row]);
    }

    public function store(StoreUserClaimRequest $request, int $item, SubmitClaim $submit, ImageFiles $files): JsonResponse
    {
        $normalized = $request->hasFile('image') ? $files->normalize($request->file('image')) : null;
        $path = $normalized ? $files->newPath() : null;
        try {
            DB::transaction(function () use ($request, $item, $submit, $files, $normalized, $path): void {
                FoundItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
                $public = DB::table('public_item_listing')->where('item_id', $item)->whereNull('unpublished_at')->exists();
                $stored = DB::table('storage_assignment')->where('item_id', $item)->whereNull('removed_at')->exists();
                if (! $public || ! $stored) {
                    throw new ConflictHttpException('This item is not currently available for public claims.');
                }
                $claimant = (int) $request->user()->person_id;
                $submit->execute($item, ['claimant_id' => $claimant, 'evidence_text' => $request->validated('evidence_text')]);
                if ($normalized) {
                    $files->put($path, $normalized['content']);
                    DB::table('claim_proof_image')->insert([
                        'item_id' => $item, 'claimant_id' => $claimant, 'path' => $path,
                        'width' => $normalized['width'], 'height' => $normalized['height'],
                        'bytes' => $normalized['bytes'], 'created_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $error) {
            if ($path) {
                // Preserve a possibly committed file when a lost acknowledgement makes rollback uncertain.
                try {
                    if (! DB::table('claim_proof_image')->where('path', $path)->exists()) {
                        $files->remove($path);
                    }
                } catch (\Throwable) {
                    Log::warning('Claim proof commit could not be confirmed; retain file for reconciliation');
                }
            }
            throw $error;
        }

        return response()->json(['data' => $this->query((int) $request->user()->person_id)->where('c.item_id', $item)->first()], 201);
    }

    private function query(int $personId)
    {
        return DB::table('claim as c')->join('found_item as item', 'item.item_id', '=', 'c.item_id')
            ->join('category', 'category.category_id', '=', 'item.category_id')
            ->leftJoin('public_item_listing as listing', function ($join): void {
                $join->on('listing.item_id', '=', 'item.item_id')->whereNull('listing.unpublished_at');
            })
            ->where('c.claimant_id', $personId)
            ->select(['c.item_id', 'c.submitted_at', 'c.evidence_text', 'c.outcome', 'c.reviewed_at',
                'category.category_name', 'item.found_at', 'listing.public_description'])
            ->selectRaw('EXISTS(SELECT 1 FROM claim_proof_image AS proof WHERE proof.item_id = c.item_id AND proof.claimant_id = c.claimant_id) AS has_proof_image');
    }
}
