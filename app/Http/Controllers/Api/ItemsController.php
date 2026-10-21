<?php

namespace App\Http\Controllers\Api;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Items\ListItemsRequest;
use App\Http\Requests\Items\ShowItemRequest;
use App\Http\Requests\Items\StorageHistoryRequest;
use App\Http\Requests\Items\StoreFoundItemRequest;
use App\Http\Requests\Items\StoreTransferRequest;
use App\Http\Resources\ItemDetailsResource;
use App\Http\Resources\StorageAssignmentResource;
use App\Models\FoundItem;
use App\Queries\Items\ItemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ItemsController extends Controller
{
    public function index(ListItemsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $status = $filters['status'] ?? 'stored';

        $items = DB::table('found_item as item')
            ->join('category as category', 'category.category_id', '=', 'item.category_id')
            ->leftJoin('storage_assignment as assignment', function (JoinClause $join): void {
                $join->on('assignment.item_id', '=', 'item.item_id')->whereNull('assignment.removed_at');
            })
            ->leftJoin('storage_location as location', 'location.location_id', '=', 'assignment.location_id')
            ->when(isset($filters['q']) && $filters['q'] !== '', function ($query) use ($filters): void {
                $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']);
                $query->whereRaw("item.item_description LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            })
            ->when(isset($filters['category_id']), fn ($query) => $query->where('item.category_id', $filters['category_id']))
            ->when(isset($filters['location_id']), function ($query) use ($filters): void {
                if (($filters['location_scope'] ?? 'current') === 'history') {
                    $query->whereExists(function ($placements) use ($filters): void {
                        $placements->selectRaw('1')
                            ->from('storage_assignment as history')
                            ->whereColumn('history.item_id', 'item.item_id')
                            ->where('history.location_id', $filters['location_id']);
                    });

                    return;
                }
                $query->where('assignment.location_id', $filters['location_id'])
                    ->whereNotExists(function ($claims): void {
                        $claims->selectRaw('1')
                            ->from('claim as returned_claim')
                            ->whereColumn('returned_claim.item_id', 'item.item_id')
                            ->where('returned_claim.outcome', 'RETURNED');
                    });
            })
            ->when(isset($filters['received_from']), function ($query) use ($filters): void {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['received_from'], config('app.timezone'));
                $query->where('item.received_at', '>=', $from->format('Y-m-d H:i:s.u'));
            })
            ->when(isset($filters['received_to']), function ($query) use ($filters): void {
                $toExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $filters['received_to'], config('app.timezone'))->addDay();
                $query->where('item.received_at', '<', $toExclusive->format('Y-m-d H:i:s.u'));
            })
            ->when($status === 'stored', function ($query): void {
                $query->whereNotNull('assignment.item_id')
                    ->whereNotExists(function ($claims): void {
                        $claims->selectRaw('1')
                            ->from('claim as returned_claim')
                            ->whereColumn('returned_claim.item_id', 'item.item_id')
                            ->where('returned_claim.outcome', 'RETURNED');
                    });
            })
            ->when($status === 'returned', function ($query): void {
                $query->whereExists(function ($claims): void {
                    $claims->selectRaw('1')
                        ->from('claim as returned_claim')
                        ->whereColumn('returned_claim.item_id', 'item.item_id')
                        ->where('returned_claim.outcome', 'RETURNED');
                });
            })
            ->when($status === 'all', function ($query): void {
                $query->where(function ($states): void {
                    $states->whereNotNull('assignment.item_id')
                        ->orWhereExists(function ($claims): void {
                            $claims->selectRaw('1')
                                ->from('claim as returned_claim')
                                ->whereColumn('returned_claim.item_id', 'item.item_id')
                                ->where('returned_claim.outcome', 'RETURNED');
                        });
                });
            })
            ->select([
                'item.item_id',
                'item.finder_id',
                'item.category_id',
                'category.category_name',
                'item.item_description',
                'item.found_place',
                'item.found_at',
                'item.received_at',
            ])
            ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM claim AS returned_location WHERE returned_location.item_id = item.item_id AND returned_location.outcome = ?) THEN NULL ELSE location.location_id END AS current_location_id", ['RETURNED'])
            ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM claim AS returned_location WHERE returned_location.item_id = item.item_id AND returned_location.outcome = ?) THEN NULL ELSE location.location_code END AS current_location_code", ['RETURNED']);

        $sortColumns = [
            'received_at' => 'item.received_at',
            'found_at' => 'item.found_at',
            'item_id' => 'item.item_id',
            'description' => 'item.item_description',
            'category' => 'category.category_name',
            'location' => 'location.location_code',
        ];

        if (isset($filters['sort_by'])) {
            $direction = $filters['sort_direction'] ?? 'desc';
            $items->orderBy($sortColumns[$filters['sort_by']], $direction);
            if ($filters['sort_by'] !== 'item_id') {
                $items->orderBy('item.item_id', $direction);
            }
        } else {
            $items->orderByDesc('item.received_at')->orderByDesc('item.item_id');
        }

        return response()->json($items->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function show(ShowItemRequest $request, int $item, ItemDetails $details): JsonResponse
    {
        return (new ItemDetailsResource($details->find($item)))->response();
    }

    public function storageHistory(StorageHistoryRequest $request, int $item): JsonResponse
    {
        FoundItem::query()->select('item_id')->whereKey($item)->firstOrFail();
        $assignments = DB::table('storage_assignment as assignment')
            ->join('storage_location as location', 'location.location_id', '=', 'assignment.location_id')
            ->join('staff as assigner', 'assigner.staff_id', '=', 'assignment.assigned_by')
            ->where('assignment.item_id', $item)
            ->orderBy('assignment.assignment_no')
            ->select([
                'assignment.*', 'location.location_code', 'location.location_description',
                'assigner.full_name as assigner_name',
            ])
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return StorageAssignmentResource::collection($assignments)->response();
    }

    public function store(StoreFoundItemRequest $request, ReceiveFoundItem $receive): JsonResponse
    {
        $staffId = (int) $request->user()->staff_id;

        $item = $receive->execute($request->validated(), $staffId);

        return response()->json([
            'item_id' => $item->item_id,
            'finder_id' => $item->finder_id,
            'category_id' => $item->category_id,
            'category_name' => $item->category->category_name,
            'item_description' => $item->item_description,
            'found_place' => $item->found_place,
            'found_at' => $item->found_at,
            'received_at' => $item->received_at,
        ], 201);
    }

    public function transfer(StoreTransferRequest $request, int $item, TransferFoundItem $transfer): JsonResponse
    {
        $staffId = (int) $request->user()->staff_id;

        $updatedItem = $transfer->execute($item, (int) $request->validated('location_id'), $staffId);

        return response()->json([
            'item_id' => $updatedItem->item_id,
            'category_id' => $updatedItem->category_id,
            'item_description' => $updatedItem->item_description,
        ]);
    }
}
