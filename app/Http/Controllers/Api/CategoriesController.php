<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReferenceData\SaveReferenceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoriesController extends Controller
{
    public function index(CategoryRequest $request): JsonResponse
    {
        $pattern = $request->searchPattern();
        $records = Category::query()
            ->when($pattern !== null, function (Builder $query) use ($pattern): void {
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("category_name LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('category_name')
            ->orderBy('category_id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return CategoryResource::collection($records)->response();
    }

    public function show(CategoryRequest $request, Category $category): JsonResponse
    {
        return (new CategoryResource($category))->response();
    }

    public function store(CategoryRequest $request, SaveReferenceData $save): JsonResponse
    {
        $record = $save->execute(new Category, $request->safe()->only(['category_name']));

        return (new CategoryResource($record))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category, SaveReferenceData $save): JsonResponse
    {
        $record = $save->execute($category, $request->safe()->only(['category_name']));

        return (new CategoryResource($record))->response();
    }
}
