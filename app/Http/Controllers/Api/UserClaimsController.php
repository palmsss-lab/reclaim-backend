<?php

namespace App\Http\Controllers\Api;

use App\Actions\Claims\SubmitClaim;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\MyReportIndexRequest;
use App\Http\Requests\User\StoreUserClaimRequest;
use App\Models\FoundItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

    public function store(StoreUserClaimRequest $request, int $item, SubmitClaim $submit): JsonResponse
    {
        $claim = DB::transaction(function () use ($request, $item, $submit) {
            FoundItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            $public = DB::table('public_item_listing')->where('item_id', $item)->whereNull('unpublished_at')->exists();
            $stored = DB::table('storage_assignment')->where('item_id', $item)->whereNull('removed_at')->exists();
            if (! $public || ! $stored) throw new ConflictHttpException('This item is not currently available for public claims.');
            return $submit->execute($item, ['claimant_id' => (int) $request->user()->person_id, 'evidence_text' => $request->validated('evidence_text')]);
        });
        return response()->json(['data' => $this->query((int) $request->user()->person_id)->where('c.item_id', $item)->first()], 201);
    }

    private function query(int $personId)
    {
        return DB::table('claim as c')->join('found_item as item', 'item.item_id', '=', 'c.item_id')
            ->join('category', 'category.category_id', '=', 'item.category_id')
            ->leftJoin('public_item_listing as listing', function ($join): void { $join->on('listing.item_id', '=', 'item.item_id')->whereNull('listing.unpublished_at'); })
            ->where('c.claimant_id', $personId)
            ->select(['c.item_id', 'c.submitted_at', 'c.evidence_text', 'c.outcome', 'c.reviewed_at',
                'category.category_name', 'item.found_at', 'listing.public_description']);
    }
}