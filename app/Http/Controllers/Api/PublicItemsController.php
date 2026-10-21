<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Items\PublicItemIndexRequest;
use App\Http\Requests\Items\PublishItemRequest;
use App\Models\FoundItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PublicItemsController extends Controller
{
    public function index(PublicItemIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = DB::table('public_item_listing as listing')
            ->join('found_item as item', 'item.item_id', '=', 'listing.item_id')
            ->join('category', 'category.category_id', '=', 'item.category_id')
            ->whereNull('listing.unpublished_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('claim as c')->whereColumn('c.item_id', 'item.item_id')->where('c.outcome', 'RETURNED'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('storage_assignment as sa')->whereColumn('sa.item_id', 'item.item_id')->whereNull('sa.removed_at'))
            ->when(isset($filters['q']) && $filters['q'] !== '', function ($query) use ($filters): void {
                $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']);
                $query->whereRaw("listing.public_description LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            })
            ->when(isset($filters['category_id']), fn ($query) => $query->where('item.category_id', $filters['category_id']))
            ->when(isset($filters['received_from']), fn ($query) => $query->where('item.received_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['received_from'], config('app.timezone'))->format('Y-m-d H:i:s.u')))
            ->when(isset($filters['received_to']), fn ($query) => $query->where('item.received_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['received_to'], config('app.timezone'))->addDay()->format('Y-m-d H:i:s.u')))
            ->select(['item.item_id', 'listing.public_description', 'item.category_id', 'category.category_name', 'item.found_at', 'item.received_at'])
            ->orderByDesc('item.received_at')->orderByDesc('item.item_id')
            ->paginate($filters['per_page'] ?? 20)->withQueryString();

        $images = ImagesController::publicMetadata($rows->getCollection()->pluck('item_id')->all());
        $rows->getCollection()->each(function ($row) use ($images): void { $row->images = $images->get($row->item_id, []); });
        return response()->json($rows)->header('Cache-Control', 'private, no-store');
    }

    public function show(int $item): JsonResponse
    {
        $row = DB::table('public_item_listing as listing')
            ->join('found_item as item', 'item.item_id', '=', 'listing.item_id')
            ->join('category', 'category.category_id', '=', 'item.category_id')
            ->where('item.item_id', $item)->whereNull('listing.unpublished_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('claim as c')->whereColumn('c.item_id', 'item.item_id')->where('c.outcome', 'RETURNED'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('storage_assignment as sa')->whereColumn('sa.item_id', 'item.item_id')->whereNull('sa.removed_at'))
            ->select(['item.item_id', 'listing.public_description', 'item.category_id', 'category.category_name', 'item.found_at', 'item.received_at'])
            ->first();
        abort_unless($row, 404);
        $row->images = ImagesController::publicMetadata([$item])->get($item, []);
        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    public function publish(PublishItemRequest $request, int $item): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $item): array {
            FoundItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            $stored = DB::table('storage_assignment')->where('item_id', $item)->whereNull('removed_at')->exists();
            $returned = DB::table('claim')->where('item_id', $item)->where('outcome', 'RETURNED')->exists();
            if (! $stored || $returned) throw new ConflictHttpException('Only currently stored items can be published.');
            $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u');
            $existing = DB::table('public_item_listing')->where('item_id', $item)->lockForUpdate()->first();
            if ($existing) {
                DB::table('public_item_listing')->where('item_id', $item)->update([
                    'public_description' => trim($request->validated('public_description')),
                    'published_by' => $request->user()->staff_id, 'published_at' => $now, 'unpublished_at' => null,
                ]);
                return ['created' => false, 'item_id' => $item, 'public_description' => trim($request->validated('public_description')), 'published_at' => $now];
            }
            DB::table('public_item_listing')->insert([
                'item_id' => $item, 'public_description' => trim($request->validated('public_description')),
                'published_by' => $request->user()->staff_id, 'published_at' => $now,
            ]);
            return ['created' => true, 'item_id' => $item, 'public_description' => trim($request->validated('public_description')), 'published_at' => $now];
        });
        return response()->json(['data' => $record], $record['created'] ? 201 : 200);
    }

    public function unpublish(Request $request, int $item): JsonResponse
    {
        $changed = DB::transaction(function () use ($request, $item): int {
            FoundItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            return DB::table('public_item_listing')->where('item_id', $item)->whereNull('unpublished_at')
                ->update(['unpublished_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u')]);
        });
        abort_unless($changed, 404, 'No active public listing exists for this item.');
        return response()->json(['message' => 'The public listing was removed.']);
    }
}