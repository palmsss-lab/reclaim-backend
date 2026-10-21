<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\MissingItemReportRequest;
use App\Http\Requests\User\MyMissingReportIndexRequest;
use App\Http\Requests\User\MyReportIndexRequest;
use App\Http\Requests\User\ReviewMissingReportRequest;
use App\Http\Requests\User\StaffReportIndexRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MissingItemReportsController extends Controller
{
    public function index(MyMissingReportIndexRequest $request): JsonResponse
    {
        return response()->json(
            $this->ownerQuery($request->user()->id)
                ->when($request->validated('status'), fn ($query, $status) => $query->where('r.status', $status))
                ->orderByDesc('r.created_at')
                ->orderByDesc('r.report_id')
                ->paginate($request->validated('per_page', 20))
                ->withQueryString()
        );
    }

    public function store(MissingItemReportRequest $request): JsonResponse
    {
        $d = $request->validated(); $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u');
        $id = DB::table('missing_item_report')->insertGetId([
            'user_id' => $request->user()->id, 'item_description' => trim($d['item_description']),
            'category_id' => $d['category_id'] ?? null,
            'lost_at' => CarbonImmutable::parse($d['lost_at'], config('app.timezone'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s.u'),
            'last_known_location' => trim($d['last_known_location']), 'status' => 'OPEN',
            'created_at' => $now, 'updated_at' => $now,
        ], 'report_id');
        return response()->json(['data' => $this->ownerRecord($id, $request->user()->id)], 201);
    }

    public function show(MyReportIndexRequest $request, int $report): JsonResponse
    {
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function update(MissingItemReportRequest $request, int $report): JsonResponse
    {
        $d = $request->validated();
        DB::transaction(function () use ($request, $report, $d): void {
            $r = DB::table('missing_item_report')->where('report_id', $report)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status !== 'OPEN') throw new ConflictHttpException('Only open missing-item reports can be edited.');
            $updates = ['updated_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u')];
            foreach (['item_description', 'last_known_location'] as $field) if (array_key_exists($field, $d)) $updates[$field] = trim($d[$field]);
            if (array_key_exists('category_id', $d)) $updates['category_id'] = $d['category_id'];
            if (array_key_exists('lost_at', $d)) $updates['lost_at'] = CarbonImmutable::parse($d['lost_at'], config('app.timezone'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s.u');
            DB::table('missing_item_report')->where('report_id', $report)->update($updates);
        });
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function resolve(Request $request, int $report): JsonResponse
    {
        $this->changeOwnerStatus($request, $report, 'RESOLVED');
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function withdraw(Request $request, int $report): JsonResponse
    {
        $this->changeOwnerStatus($request, $report, 'WITHDRAWN');
        return response()->json(['data' => $this->ownerRecord($report, $request->user()->id)]);
    }

    public function staffIndex(StaffReportIndexRequest $request): JsonResponse
    {
        $f = $request->validated();
        $rows = $this->staffQuery()
            ->when(isset($f['status']), fn ($q) => $q->where('r.status', $f['status']))
            ->when(isset($f['category_id']), fn ($q) => $q->where('r.category_id', $f['category_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('r.lost_at', '>=', $f['from'].' 00:00:00'))
            ->when(isset($f['to']), fn ($q) => $q->where('r.lost_at', '<', CarbonImmutable::parse($f['to'], config('app.timezone'))->addDay()->format('Y-m-d H:i:s')))
            ->when(! empty($f['q']), function ($q) use ($f): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']).'%';
                $q->where(function ($nested) use ($pattern): void { $nested->whereRaw("r.item_description LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("r.last_known_location LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("u.email LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("p.full_name LIKE ? ESCAPE '!'", [$pattern]); });
            })
            ->orderByDesc('r.created_at')->orderByDesc('r.report_id')
            ->paginate($f['per_page'] ?? 20)->withQueryString();
        return response()->json($rows);
    }

    public function staffShow(StaffReportIndexRequest $request, int $report): JsonResponse
    {
        $row = $this->staffQuery()->where('r.report_id', $report)->first(); abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function review(ReviewMissingReportRequest $request, int $report): JsonResponse
    {
        DB::transaction(function () use ($request, $report): void {
            $r = DB::table('missing_item_report')->where('report_id', $report)->lockForUpdate()->first();
            abort_unless($r, 404);
            if (! in_array($r->status, ['OPEN', 'RESOLVED'], true)) throw new ConflictHttpException('Withdrawn reports cannot be reviewed.');
            DB::table('missing_item_report')->where('report_id', $report)->update([
                'reviewed_by' => $request->user()->staff_id,
                'reviewed_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u'),
                'updated_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u'),
            ]);
        });
        return response()->json(['data' => $this->staffQuery()->where('r.report_id', $report)->first()]);
    }

    private function changeOwnerStatus(Request $request, int $report, string $status): void
    {
        DB::transaction(function () use ($request, $report, $status): void {
            $r = DB::table('missing_item_report')->where('report_id', $report)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status !== 'OPEN') throw new ConflictHttpException('Only open missing-item reports can be resolved or withdrawn.');
            DB::table('missing_item_report')->where('report_id', $report)->update(['status' => $status, 'updated_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s.u')]);
        });
    }

    private function ownerRecord(int $report, int $userId): object
    {
        $r = $this->ownerQuery($userId)->where('r.report_id', $report)->first(); abort_unless($r, 404); return $r;
    }

    private function ownerQuery(int $userId)
    {
        return DB::table('missing_item_report as r')->leftJoin('category as c', 'c.category_id', '=', 'r.category_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'r.reviewed_by')->where('r.user_id', $userId)
            ->select(['r.report_id', 'r.item_description', 'r.category_id', 'c.category_name', 'r.lost_at', 'r.last_known_location', 'r.status', 'r.reviewed_at', 'reviewer.full_name as reviewer_name', 'r.created_at']);
    }

    private function staffQuery()
    {
        return DB::table('missing_item_report as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('person as p', 'p.person_id', '=', 'u.person_id')->leftJoin('category as c', 'c.category_id', '=', 'r.category_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'r.reviewed_by')
            ->select(['r.report_id', 'r.item_description', 'r.category_id', 'c.category_name', 'r.lost_at', 'r.last_known_location', 'r.status', 'r.reviewed_at', 'reviewer.full_name as reviewer_name', 'r.created_at', 'p.full_name as reporter_name', 'p.contact_details as reporter_contact', 'u.email as reporter_email']);
    }
}