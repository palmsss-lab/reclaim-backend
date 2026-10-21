<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReferenceData\SaveReferenceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\StorageLocationRequest;
use App\Http\Resources\StorageLocationResource;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class StorageLocationsController extends Controller
{
    public function index(StorageLocationRequest $request): JsonResponse
    {
        $pattern = $request->searchPattern();
        $records = StorageLocation::query()
            ->when($pattern !== null, function (Builder $query) use ($pattern): void {
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("location_code LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("location_description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('location_code')
            ->orderBy('location_id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return StorageLocationResource::collection($records)->response();
    }

    public function show(StorageLocationRequest $request, StorageLocation $location): JsonResponse
    {
        return (new StorageLocationResource($location))->response();
    }

    public function store(StorageLocationRequest $request, SaveReferenceData $save): JsonResponse
    {
        $record = $save->execute(new StorageLocation, $request->safe()->only(['location_code', 'location_description']));

        return (new StorageLocationResource($record))->response()->setStatusCode(201);
    }

    public function update(StorageLocationRequest $request, StorageLocation $location, SaveReferenceData $save): JsonResponse
    {
        $record = $save->execute($location, $request->safe()->only(['location_code', 'location_description']));

        return (new StorageLocationResource($record))->response();
    }
}
