<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReferenceData\SaveReferenceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\PersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PeopleController extends Controller
{
    public function index(PersonRequest $request): JsonResponse
    {
        $pattern = $request->searchPattern();
        $records = Person::query()
            ->withExists('account')
            ->when($pattern !== null, function (Builder $query) use ($pattern): void {
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("full_name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("contact_details LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('full_name')
            ->orderBy('person_id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return PersonResource::collection($records)->response();
    }

    public function show(PersonRequest $request, Person $person): JsonResponse
    {
        return (new PersonResource($person->loadExists('account')))->response();
    }

    public function store(PersonRequest $request, SaveReferenceData $save): JsonResponse
    {
        $record = $save->execute(new Person, $request->safe()->only(['full_name', 'contact_details']));

        return (new PersonResource($record->loadExists('account')))->response()->setStatusCode(201);
    }

    public function update(PersonRequest $request, Person $person, SaveReferenceData $save): JsonResponse
    {
        // Account holders manage their own profile through /me/profile.
        // Keep walk-in finder corrections available without permitting account edits.
        abort_if(User::where('person_id', $person->person_id)->exists(), 403,
            'Account profile details can only be updated by the account holder.');
        $record = $save->execute($person, $request->safe()->only(['full_name', 'contact_details']));

        return (new PersonResource($record->loadExists('account')))->response();
    }
}
