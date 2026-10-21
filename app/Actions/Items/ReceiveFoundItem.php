<?php

namespace App\Actions\Items;

use App\Models\FoundItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveFoundItem
{
    /** @param array{finder_id: int, category_id: int, initial_location_id: int, item_description: string, found_place: string, found_at: string} $data */
    public function execute(array $data, int $staffId): FoundItem
    {
        return DB::transaction(function () use ($data, $staffId): FoundItem {
            $timezone = config('app.timezone');
            $receivedAt = CarbonImmutable::now($timezone);
            $foundAt = CarbonImmutable::parse($data['found_at'], $timezone)->setTimezone($timezone);

            if ($foundAt->greaterThan($receivedAt)) {
                throw ValidationException::withMessages([
                    'found_at' => 'The found time cannot be later than the receipt time.',
                ]);
            }

            $item = new FoundItem([
                'finder_id' => $data['finder_id'],
                'category_id' => $data['category_id'],
                'item_description' => $data['item_description'],
                'found_place' => $data['found_place'],
                'found_at' => $foundAt,
                'received_at' => $receivedAt,
            ]);
            $item->received_by = $staffId;
            $item->save();

            DB::table('storage_assignment')->insert([
                'item_id' => $item->item_id,
                'assignment_no' => 1,
                'location_id' => $data['initial_location_id'],
                'assigned_by' => $staffId,
                'assigned_at' => $receivedAt->format('Y-m-d H:i:s.u'),
                'removed_at' => null,
            ]);

            return $item->load('category');
        });
    }
}