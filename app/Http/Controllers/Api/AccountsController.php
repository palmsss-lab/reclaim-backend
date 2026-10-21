<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\AccountAdministration;
use App\Actions\Accounts\BrowserSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AccountIndexRequest;
use App\Http\Requests\Accounts\ChangePasswordRequest;
use App\Http\Requests\Accounts\SetAdministratorRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AccountsController extends Controller
{
    public function index(AccountIndexRequest $request): JsonResponse
    {
        $pattern = $request->validated('q');
        $records = User::query()->with('staff')
            ->when(is_string($pattern) && $pattern !== '', function (Builder $query) use ($pattern): void {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $pattern).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('staff', fn (Builder $staff) => $staff->whereRaw("full_name LIKE ? ESCAPE '!'", [$like]));
                });
            })
            ->orderBy('name')->orderBy('id')
            ->paginate($request->validated('per_page') ?? 20)->withQueryString();

        return AccountResource::collection($records)->response();
    }

    public function show(AccountIndexRequest $request, User $account): JsonResponse
    {
        return (new AccountResource($account->load('staff')))->response();
    }

    public function store(StoreAccountRequest $request, AccountAdministration $accounts): JsonResponse
    {
        $account = $accounts->create($request->user(), $request->validated());

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, User $account, AccountAdministration $accounts): JsonResponse
    {
        $updated = $accounts->update($request->user(), $account, $request->validated());

        return (new AccountResource($updated))->response();
    }

    public function setAdministrator(SetAdministratorRequest $request, User $account, AccountAdministration $accounts): JsonResponse
    {
        $updated = $accounts->setAdministrator($request->user(), $account, $request->boolean('is_account_admin'));

        return (new AccountResource($updated))->response();
    }

    public function deactivate(AccountIndexRequest $request, User $account, AccountAdministration $accounts, BrowserSession $sessions): Response
    {
        $accounts->deactivate($request->user(), $account);
        if ($request->user()->id === $account->id) {
            $sessions->end($request);
        }

        return response()->noContent();
    }

    public function changePassword(ChangePasswordRequest $request, AccountAdministration $accounts, BrowserSession $sessions): Response
    {
        $accounts->changePassword(
            $request->user(), $request->validated('current_password'), $request->validated('password')
        );

        $sessions->end($request);

        return response()->noContent();
    }
}
