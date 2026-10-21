<?php

namespace App\Http\Controllers\Api;

use App\Actions\Items\ReceiveFoundItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\FinderReportRequest;
use App\Http\Requests\User\MyReportIndexRequest;
use App\Http\Requests\User\ReceiveFinderReportRequest;
use App\Http\Requests\User\StaffReportIndexRequest;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinderReportsController extends Controller
{
    public function index(MyReportIndexRequest $request): JsonResponse
    {
        $rows = DB::table('finder_report as r')->leftJoin('category as c', 'c.category_id', '=', 'r.category_id')
            ->where('r.user_id', $request->user()->id)->select(['r.report_id', 'r.item_description', 'r.found_place', 'r.found_at', 'r.submitted_at', 'r.status', 'r.reviewed_at', 'r.received_item_id', 'r.category_id', 'c.category_name', 'r.created_at'])
            ->orderByDesc('r.created_at')->orderByDesc('r.report_id')->paginate($request->validated('per_page', 20))->withQueryString();
        return response()->json($rows);
    }

    public function store(FinderReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u');
        $id = DB::table('finder_report')->insertGetId([
            'user_id' => $request->user()->id, 'item_description' => trim($data['item_description']),
            'found_place' => trim($data['found_place']), 'found_at' => CarbonImmutable::parse($data['found_at'], config('app.timezone'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s.u'),
            'category_id' => $data['category_id'] ?? null, 'submitted_at' => $now, 'status' => 'PENDING',
            'created_at' => $now, 'updated_at' => $now,
        ], 'report_id');
        return response()->json(['data' => $this->ownerRecord($id, $request->user()->id)], 201);
    }

    public function show(MyReportIndexRequest $request, int $report): JsonResponse
    {
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function withdraw(Request $request, int $report): JsonResponse
    {
        DB::transaction(function () use ($request, $report): void {
            $row = DB::table('finder_report')->where('report_id', $report)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'PENDING') throw new ConflictHttpException('Only pending finder reports can be withdrawn.');
            DB::table('finder_report')->where('report_id', $report)->update(['status' => 'WITHDRAWN', 'updated_at' => now(config('app.timezone'))]);
        });
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function staffIndex(StaffReportIndexRequest $request): JsonResponse
    {
        $f = $request->validated();
        $rows = $this->staffQuery()
            ->when(isset($f['status']), fn ($q) => $q->where('r.status', $f['status']))
            ->when(isset($f['category_id']), fn ($q) => $q->where('r.category_id', $f['category_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('r.submitted_at', '>=', $f['from'].' 00:00:00'))
            ->when(isset($f['to']), fn ($q) => $q->where('r.submitted_at', '<', CarbonImmutable::parse($f['to'], config('app.timezone'))->addDay()->format('Y-m-d H:i:s')))
            ->when(! empty($f['q']), function ($q) use ($f): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']).'%';
                $q->where(function ($nested) use ($pattern): void { $nested->whereRaw("r.item_description LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("r.found_place LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("u.email LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("p.full_name LIKE ? ESCAPE '!'", [$pattern]); });
            })
            ->orderByDesc('r.submitted_at')->orderByDesc('r.report_id')
            ->paginate($f['per_page'] ?? 20)->withQueryString();
        return response()->json($rows);
    }

    public function staffShow(StaffReportIndexRequest $request, int $report): JsonResponse
    {
        $row = $this->staffQuery()->where('r.report_id', $report)->first();
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function receive(ReceiveFinderReportRequest $request, int $report, ReceiveFoundItem $receive): JsonResponse
    {
        $data = $request->validated();
        $item = DB::transaction(function () use ($request, $report, $receive, $data) {
            $r = DB::table('finder_report')->where('report_id', $report)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status !== 'PENDING' || $r->received_item_id !== null) throw new ConflictHttpException('This finder report is no longer pending handover.');
            $personId = User::query()->whereKey($r->user_id)->value('person_id');
            abort_unless($personId, 409, 'The reporting account has no linked person record.');
            $item = $receive->execute([
                'finder_id' => $personId, 'category_id' => $data['category_id'], 'initial_location_id' => $data['initial_location_id'],
                'item_description' => $r->item_description, 'found_place' => $r->found_place, 'found_at' => $r->found_at,
            ], (int) $request->user()->staff_id);
            $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u');
            DB::table('finder_report')->where('report_id', $report)->update([
                'status' => 'RECEIVED', 'reviewed_by' => $request->user()->staff_id,
                'reviewed_at' => $now, 'received_item_id' => $item->item_id, 'updated_at' => $now,
            ]);
            return $item;
        });
        return response()->json(['data' => ['report_id' => $report, 'status' => 'RECEIVED', 'item_id' => (int) $item->item_id]], 201);
    }

    public function close(Request $request, int $report): JsonResponse
    {
        DB::transaction(function () use ($request, $report): void {
            $r = DB::table('finder_report')->where('report_id', $report)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status !== 'PENDING') throw new ConflictHttpException('Only pending finder reports can be closed.');
            $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u');
            DB::table('finder_report')->where('report_id', $report)->update(['status' => 'CLOSED', 'reviewed_by' => $request->user()->staff_id, 'reviewed_at' => $now, 'updated_at' => $now]);
        });
        return response()->json(['data' => $this->staffQuery()->where('r.report_id', $report)->first()]);
    }

    private function ownerRecord(int $report, int $userId): object
    {
        $row = DB::table('finder_report as r')->leftJoin('category as c', 'c.category_id', '=', 'r.category_id')
            ->where('r.report_id', $report)->where('r.user_id', $userId)
            ->select(['r.report_id', 'r.item_description', 'r.found_place', 'r.found_at', 'r.submitted_at', 'r.status', 'r.reviewed_at', 'r.received_item_id', 'r.category_id', 'c.category_name', 'r.created_at'])->first();
        abort_unless($row, 404);
        return $row;
    }

    private function staffQuery()
    {
        return DB::table('finder_report as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('person as p', 'p.person_id', '=', 'u.person_id')
            ->leftJoin('category as c', 'c.category_id', '=', 'r.category_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'r.reviewed_by')
            ->select(['r.report_id', 'r.item_description', 'r.found_place', 'r.found_at', 'r.submitted_at', 'r.status', 'r.reviewed_at', 'r.received_item_id', 'r.category_id', 'c.category_name', 'r.created_at', 'p.full_name as reporter_name', 'p.contact_details as reporter_contact', 'u.email as reporter_email', 'reviewer.full_name as reviewer_name']);
    }
}