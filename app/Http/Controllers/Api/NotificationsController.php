<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\MyReportIndexRequest;
use App\Http\Requests\User\NotifyPossibleMatchRequest;
use App\Services\InAppNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationsController extends Controller
{
    public function index(MyReportIndexRequest $request): JsonResponse
    {
        $page = $this->own($request)->orderByDesc('created_at')->orderByDesc('notification_id')->paginate($request->validated('per_page', 20));
        $visible = DB::table('public_item_listing as listing')->whereIn('listing.item_id', $page->getCollection()->pluck('item_id')->filter()->all())
            ->whereNull('listing.unpublished_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('storage_assignment as s')->whereColumn('s.item_id', 'listing.item_id')->whereNull('s.removed_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('claim as c')->whereColumn('c.item_id', 'listing.item_id')->where('c.outcome', 'RETURNED'))
            ->pluck('listing.item_id')->all();
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row, $visible)));
        return response()->json($page);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->own($request)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $query = $this->own($request)->where('notification_id', $notification);
        abort_unless((clone $query)->exists(), 404);
        (clone $query)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u')]);
        // The read response intentionally omits possible-item links; listing rechecks availability.
        return response()->json(['data' => $this->present($query->first())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->own($request)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u')]);
        return $this->unreadCount($request);
    }

    public function possibleMatch(NotifyPossibleMatchRequest $request, int $report, InAppNotifications $notifications): JsonResponse
    {
        $notifications->possibleMatch($report, (int) $request->validated('item_id'));
        return response()->json(['message' => 'Possible-match notification recorded.']);
    }

    private function own(Request $request)
    {
        return DB::table('in_app_notification')->where('user_id', $request->user()->id);
    }

    private function present(object $row, array $visible = []): array
    {
        $date = static fn ($value) => $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
        return [
            'notification_id' => (int) $row->notification_id, 'kind' => $row->kind, 'message' => $row->message,
            'target_type' => $row->target_type, 'target_id' => (int) $row->target_id,
            'possible_item_id' => in_array($row->item_id, $visible) ? (int) $row->item_id : null,
            'created_at' => $date($row->created_at), 'read_at' => $date($row->read_at),
        ];
    }
}
