<?php

namespace App\Queries\Items;

use App\Models\FoundItem;
use Illuminate\Database\Query\JoinClause;

class ItemDetails
{
    public function find(int $item): FoundItem
    {
        // One statement gives a consistent view of placement and return state.
        return FoundItem::query()->from('found_item as item')
            ->join('person as finder', 'finder.person_id', '=', 'item.finder_id')
            ->join('category', 'category.category_id', '=', 'item.category_id')
            ->join('staff as receiver', 'receiver.staff_id', '=', 'item.received_by')
            ->leftJoin('storage_assignment as placement', function (JoinClause $join): void {
                $join->on('placement.item_id', '=', 'item.item_id')->whereNull('placement.removed_at');
            })
            ->leftJoin('storage_location as location', 'location.location_id', '=', 'placement.location_id')
            ->leftJoin('staff as assigner', 'assigner.staff_id', '=', 'placement.assigned_by')
            ->leftJoin('claim as returned', function (JoinClause $join): void {
                $join->on('returned.item_id', '=', 'item.item_id')->where('returned.outcome', 'RETURNED');
            })
            ->leftJoin('person as claimant', 'claimant.person_id', '=', 'returned.claimant_id')
            ->leftJoin('staff as reviewer', 'reviewer.staff_id', '=', 'returned.reviewed_by')
            ->where('item.item_id', $item)
            ->select([
                'item.*',
                'finder.full_name as finder_name', 'finder.contact_details as finder_contact',
                'category.category_name', 'receiver.full_name as receiver_name',
                'placement.assignment_no', 'placement.assigned_by', 'placement.assigned_at',
                'location.location_id', 'location.location_code', 'location.location_description',
                'assigner.full_name as assigner_name',
                'returned.claimant_id as returned_claimant_id', 'returned.reviewed_by', 'returned.reviewed_at',
                'claimant.full_name as claimant_name', 'claimant.contact_details as claimant_contact',
                'reviewer.full_name as reviewer_name',
            ])->firstOrFail();
    }
}
