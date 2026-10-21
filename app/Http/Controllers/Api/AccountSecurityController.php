<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\BrowserSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupPasswordRequest;
use App\Http\Requests\Auth\UpdateUsernameRequest;
use App\Models\User;
use App\Notifications\SetReclaimPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountSecurityController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => ['username' => $request->user()->username, 'has_local_password' => (bool) $request->user()->has_local_password,
            'google_linked' => DB::table('google_identities')->where('user_id', $request->user()->id)->exists(),
            'email_verified' => $request->user()->hasVerifiedEmail()]])->header('Cache-Control', 'no-store');
    }

    public function updateUsername(UpdateUsernameRequest $request): JsonResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->isActive(), 403, 'This account is inactive.');
                // Recheck the password under the same account lock as the write.
                if ($user->has_local_password && ! Hash::check((string) $request->input('current_password'), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'The password is incorrect.']);
                }
                $user->username = $request->validated('username');
                $user->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => 'This username is unavailable. Choose another.']);
        }

        return response()->json(['data' => ['username' => $request->validated('username')], 'message' => 'Username saved. You can sign in with your username or email.'])->header('Cache-Control', 'no-store');
    }

    public function sendSetup(Request $request)
    {
        $proof = Str::random(64);
        DB::transaction(function () use ($request, $proof) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->isActive() && ! $user->has_local_password && $user->hasVerifiedEmail(), 409,
                'Password setup requires a verified account without a Reclaim password.');
            DB::table('password_setup_tokens')->updateOrInsert(['user_id' => $user->id],
                ['token_hash' => hash('sha256', $proof), 'email' => $user->email, 'expires_at' => now()->addMinutes(15)]);
            $user->notify(new SetReclaimPassword($proof));
        });

        return response()->json(['message' => 'A single-use password setup link was sent to your verified email.']);
    }

    public function setPassword(SetupPasswordRequest $request, BrowserSession $sessions)
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $proof = DB::table('password_setup_tokens')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($user->isActive() && ! $user->has_local_password && $user->hasVerifiedEmail() &&
                $proof && hash_equals($proof->token_hash, hash('sha256', $data['proof'])) &&
                $proof->email === $user->email && now()->lt($proof->expires_at), 422,
                'The password setup link is invalid, expired, or already used. Request a new link from Account Settings.');
            $user->password = $data['password'];
            $user->has_local_password = true;
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('password_setup_tokens')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->tokens()->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        });

        $sessions->end($request);

        return response()->noContent();
    }
}
