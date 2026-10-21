<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\BrowserSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function store(LoginRequest $request, BrowserSession $sessions): JsonResponse
    {
        $credentials = $request->validated();
        $login = $credentials['login'] ?? $credentials['email'];
        $column = str_contains($login, '@') ? 'email' : 'username';
        $user = User::query()->with(['staff', 'person'])
            ->where($column, $login)->whereNull('deactivated_at')->first();
        if (! $user || ! $user->has_local_password || ! Hash::check($credentials['password'], $user->password) || (! $user->staff_id && ! $user->person_id)) {
            return response()->json(['message' => 'The provided credentials are invalid.'], 401);
        }
        if ($user->staff_id && $user->staff) {
            return response()->json($sessions->authenticate($request, $user, 'reclaim-api') + [
                'staff' => ['staff_id' => $user->staff->staff_id, 'full_name' => $user->staff->full_name],
            ]);
        }

        return response()->json($sessions->authenticate($request, $user, 'reclaim-api') + [
            'user' => ['id' => (int) $user->id, 'person_id' => (int) $user->person_id,
                'full_name' => $user->person->full_name, 'email_verified' => $user->hasVerifiedEmail()],
        ]);
    }

    public function destroy(Request $request, BrowserSession $sessions): Response
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $sessions->end($request);

        return response()->noContent();
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['staff', 'person']);
        if ($user->staff) {
            return response()->json([
                'staff_id' => $user->staff->staff_id,
                'full_name' => $user->staff->full_name,
                'has_local_password' => (bool) $user->has_local_password,
                'permissions' => ['manage_accounts' => $user->can('manageAccounts', User::class)],
            ]);
        }
        abort_unless($user->person, 403, 'This account is not linked to a person or staff record.');

        return response()->json(['user_id' => (int) $user->id, 'person_id' => (int) $user->person_id,
            'full_name' => $user->person->full_name, 'email' => $user->email, 'email_verified' => $user->hasVerifiedEmail(),
            'has_local_password' => (bool) $user->has_local_password]);
    }
}
