<?php

namespace App\Queries\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReclaimReports
{
    public function pendingClaims(array $filters): LengthAwarePaginator
    {
        $query = DB::table('claim as claim')
            ->join('found_item as item', 'item.item_id', '=', 'claim.item_id')
            ->join('category as category', 'category.category_id', '=', 'item.category_id')
            ->join('person as claimant', 'claimant.person_id', '=', 'claim.claimant_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'claim.reviewed_by')
            ->where('claim.outcome', 'PENDING')
            ->when(isset($filters['category_id']), fn ($query) => $query->where('item.category_id', $filters['category_id']))
            ->select([
                'claim.item_id', 'claim.claimant_id', 'claim.submitted_at', 'claim.evidence_text',
                'claim.outcome', 'claim.reviewed_by', 'claim.reviewed_at',
                'claimant.full_name as claimant_name', 'claimant.contact_details as claimant_contact',
                'reviewer.full_name as reviewer_name', 'item.item_description',
                'item.category_id as item_category_id', 'category.category_name as item_category_name',
                'item.received_at as item_received_at',
            ])
            ->selectRaw('EXISTS(SELECT 1 FROM claim_proof_image AS proof WHERE proof.item_id = claim.item_id AND proof.claimant_id = claim.claimant_id) AS has_proof_image');

        $this->applyReceivedDateRange($query, $filters, 'item.received_at');

        return $query->orderByDesc('claim.submitted_at')
            ->orderBy('claim.item_id')
            ->orderBy('claim.claimant_id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    public function returnRates(array $filters): LengthAwarePaginator
    {
        $totals = DB::table('found_item as item')
            ->select('item.category_id')
            ->selectRaw('COUNT(*) AS received_items')
            ->selectRaw("SUM(CASE WHEN EXISTS (SELECT 1 FROM claim AS returned_claim WHERE returned_claim.item_id = item.item_id AND returned_claim.outcome = ?) THEN 1 ELSE 0 END) AS returned_items", ['RETURNED']);
        $this->applyReceivedDateRange($totals, $filters, 'item.received_at');
        $totals->groupBy('item.category_id');

        $report = DB::table('category as category')
            ->leftJoinSub($totals, 'totals', fn ($join) => $join->on('totals.category_id', '=', 'category.category_id'))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category.category_id', $filters['category_id']))
            ->select([
                'category.category_id', 'category.category_name',
            ])
            ->selectRaw('COALESCE(totals.received_items, 0) AS received_items')
            ->selectRaw('COALESCE(totals.returned_items, 0) AS returned_items')
            ->orderBy('category.category_id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $report->through(function ($row): array {
            $received = (int) $row->received_items;
            $returned = (int) $row->returned_items;

            return [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'received_items' => $received,
                'returned_items' => $returned,
                'return_rate_percent' => $received === 0 ? null : round(($returned / $received) * 100, 2),
            ];
        });
    }

    public function storageOccupancy(array $filters): LengthAwarePaginator
    {
        $asOf = CarbonImmutable::now(config('app.timezone'));
        $asOfDatabase = $asOf->format('Y-m-d H:i:s.u');
        $storedByLocation = DB::table('storage_assignment as assignment')
            ->join('found_item as item', 'item.item_id', '=', 'assignment.item_id')
            ->whereNull('assignment.removed_at')
            ->whereNotExists(function ($claims): void {
                $claims->selectRaw('1')
                    ->from('claim as returned_claim')
                    ->whereColumn('returned_claim.item_id', 'item.item_id')
                    ->where('returned_claim.outcome', 'RETURNED');
            })
            ->groupBy('assignment.location_id')
            ->select('assignment.location_id')
            ->selectRaw('COUNT(*) AS current_item_count')
            ->selectRaw('AVG(CAST(TIMESTAMPDIFF(MICROSECOND, item.received_at, ?) AS DECIMAL(30, 12)) / CAST(86400000000 AS DECIMAL(14, 0))) AS average_holding_days', [$asOfDatabase]);

        $report = DB::table('storage_location as location')
            ->leftJoinSub($storedByLocation, 'occupancy', fn ($join) => $join->on('occupancy.location_id', '=', 'location.location_id'))
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location.location_id', $filters['location_id']))
            ->select(['location.location_id', 'location.location_code', 'location.location_description'])
            ->selectRaw('COALESCE(occupancy.current_item_count, 0) AS current_item_count')
            ->addSelect('occupancy.average_holding_days')
            ->orderBy('location.location_id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $report->through(fn ($row): array => [
            'location_id' => (int) $row->location_id,
            'location_code' => $row->location_code,
            'location_description' => $row->location_description,
            'current_item_count' => (int) $row->current_item_count,
            'average_holding_days' => $row->average_holding_days === null ? null : round((float) $row->average_holding_days, 6),
            'measured_at' => $asOf->format('Y-m-d\TH:i:s.uP'),
        ]);
    }

    public function monthlyIntake(array $filters): LengthAwarePaginator
    {
        $baseline = 0;
        if (isset($filters['received_from'])) {
            $start = $this->dateStart($filters['received_from']);
            $baseline = DB::table('found_item')->where('received_at', '<', $start)->when(
                isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id'])
            )->count();
        }

        $monthly = DB::table('found_item as item')
            ->selectRaw("DATE_FORMAT(item.received_at, '%Y-%m') AS report_month")
            ->selectRaw('COUNT(*) AS monthly_intake')
            ->when(isset($filters['category_id']), fn ($query) => $query->where('item.category_id', $filters['category_id']));
        $this->applyReceivedDateRange($monthly, $filters, 'item.received_at');
        $monthly->groupByRaw("DATE_FORMAT(item.received_at, '%Y-%m')");

        return DB::query()
            ->fromSub($monthly, 'monthly')
            ->select('report_month', 'monthly_intake')
            ->selectRaw('SUM(monthly_intake) OVER (ORDER BY report_month ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) + ? AS running_total', [$baseline])
            ->orderBy('report_month')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString()
            ->through(fn ($row): array => [
                'month' => $row->report_month,
                'monthly_intake' => (int) $row->monthly_intake,
                'running_total' => (int) $row->running_total,
            ]);
    }

    private function applyReceivedDateRange(Builder $query, array $filters, string $column): void
    {
        if (isset($filters['received_from'])) {
            $query->where($column, '>=', $this->dateStart($filters['received_from']));
        }

        if (isset($filters['received_to'])) {
            $endExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $filters['received_to'], config('app.timezone'))
                ->addDay()->format('Y-m-d H:i:s.u');
            $query->where($column, '<', $endExclusive);
        }
    }

    private function dateStart(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'))->format('Y-m-d H:i:s.u');
    }
}
