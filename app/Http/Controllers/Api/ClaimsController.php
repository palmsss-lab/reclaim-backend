<?php

namespace App\Http\Controllers\Api;

use App\Actions\Claims\RejectClaim;
use App\Actions\Claims\SubmitClaim;
use App\Actions\Items\ReturnFoundItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Claims\ListClaimsRequest;
use App\Http\Requests\Claims\RejectClaimRequest;
use App\Http\Requests\Claims\ReturnItemRequest;
use App\Http\Requests\Claims\StoreClaimRequest;
use App\Http\Resources\ClaimHistoryResource;
use App\Http\Resources\ClaimResource;
use App\Models\FoundItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClaimsController extends Controller
{
    public function store(StoreClaimRequest $request, int $item, SubmitClaim $submit): JsonResponse
    {
        abort_if(User::query()->where('person_id', $request->validated('claimant_id'))->exists(), 403,
            'Account holders must submit their own claims.');
        $claim = $submit->execute($item, $request->validated());

        return (new ClaimResource($claim))->response()->setStatusCode(201);
    }

    public function reject(RejectClaimRequest $request, int $item, int $claimant, RejectClaim $reject): JsonResponse
    {
        $claim = $reject->execute($item, $claimant, (int) $request->user()->staff_id, $request->validated('reviewed_at'));

        return (new ClaimResource($claim))->response();
    }

    public function returnItem(ReturnItemRequest $request, int $item, int $claimant, ReturnFoundItem $return): JsonResponse
    {
        $claim = $return->execute($item, $claimant, (int) $request->user()->staff_id, $request->validated('returned_at'));

        return (new ClaimResource($claim))->response();
    }

    public function index(ListClaimsRequest $request, int $item): JsonResponse
    {
        FoundItem::query()->select('item_id')->whereKey($item)->firstOrFail();
        $filters = $request->validated();

        $claims = DB::table('claim')
            ->join('person as claimant', 'claimant.person_id', '=', 'claim.claimant_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'claim.reviewed_by')
            ->select([
                'claim.*', 'claimant.full_name as claimant_name',
                'claimant.contact_details as claimant_contact', 'reviewer.full_name as reviewer_name',
            ])
            ->selectRaw('EXISTS(SELECT 1 FROM claim_proof_image AS proof WHERE proof.item_id = claim.item_id AND proof.claimant_id = claim.claimant_id) AS has_proof_image')
            ->where('claim.item_id', $item)
            ->when(isset($filters['outcome']), fn ($query) => $query->where('claim.outcome', $filters['outcome']))
            ->orderByDesc('claim.submitted_at')
            ->orderBy('claim.claimant_id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return ClaimHistoryResource::collection($claims)->response();
    }
}
