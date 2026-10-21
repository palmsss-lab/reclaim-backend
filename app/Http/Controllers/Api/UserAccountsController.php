<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\BrowserSession;
use App\Actions\Accounts\RecoverPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\ResetUserPasswordRequest;
use App\Http\Requests\Auth\UpdateUserProfileRequest;
use App\Http\Requests\Auth\VerifyPasswordResetCodeRequest;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccountsController extends Controller
{
    public function register(RegisterUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $user = DB::transaction(function () use ($data): User {
                $person = Person::create(['full_name' => $data['full_name'], 'contact_details' => $data['contact_details']]);
                $user = new User;
                $user->name = $person->full_name;
                $user->email = $data['email'];
                $user->username = $data['username'] ?? null;
                $user->password = $data['password'];
                $user->person()->associate($person);
                $user->is_account_admin = false;
                $user->deactivated_at = null;
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            if (isset($data['username']) && User::where('username', $data['username'])->exists()) {
                throw ValidationException::withMessages(['username' => 'This username is unavailable. Choose another.']);
            }
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
        $user->sendEmailVerificationNotification();

        return response()->json(['data' => [
            'id' => (int) $user->id, 'email' => $user->email,
            'full_name' => $user->name, 'email_verified' => false,
        ], 'message' => 'Registration complete. Check your email to verify the address before using user features.'], 201);
    }

    public function forgotPassword(Request $request, RecoverPassword $recovery): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($request->string('email')->toString()));
        $user = User::where('email', $email)->where('has_local_password', true)->whereNull('deactivated_at')->first();
        if ($user) {
            $recovery->send($user);
        }

        return response()->json(['message' => 'If an active account matches that address, check your email for a reset code. Codes expire after 10 minutes. Wait one minute before requesting another.']);
    }

    public function verifyResetCode(VerifyPasswordResetCodeRequest $request, RecoverPassword $recovery): JsonResponse
    {
        return response()->json(['data' => [
            'authorization' => $recovery->verify($request->validated()),
            'expires_in' => RecoverPassword::AUTHORIZATION_SECONDS,
        ]])->header('Cache-Control', 'no-store');
    }

    public function resetPassword(ResetUserPasswordRequest $request, BrowserSession $sessions, RecoverPassword $recovery): JsonResponse
    {
        $recovery->reset($request->validated());
        $sessions->end($request);

        return response()->json(['message' => 'Password reset. Sign in with the new password.']);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse|Response
    {
        abort_unless(hash_equals(sha1((string) User::query()->whereKey($id)->value('email')), $hash), 403, 'The verification link is invalid.');
        $user = User::query()->whereKey($id)->whereNull('deactivated_at')->firstOrFail();
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        if ($request->prefers(['application/json', 'text/html']) === 'text/html') {
            return response()->view('auth.verification-result', ['verified' => true])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json(['message' => 'Email verified. You can now sign in to Reclaim.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->person_id && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If verification is required, a new email has been sent.']);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('person');

        return response()->json(['data' => [
            'id' => (int) $user->id, 'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'person' => ['person_id' => (int) $user->person->person_id,
                'full_name' => $user->person->full_name, 'contact_details' => $user->person->contact_details],
        ]]);
    }

    public function updateProfile(UpdateUserProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $user = DB::transaction(function () use ($request, $data): User {
                $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $person = Person::query()->whereKey($user->person_id)->lockForUpdate()->firstOrFail();
                foreach (['full_name', 'contact_details'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $person->{$field} = trim($data[$field]);
                    }
                }
                $person->save();
                $user->name = $person->full_name;
                if (array_key_exists('email', $data) && $user->email !== $data['email']) {
                    $user->email = $data['email'];
                    $user->email_verified_at = null;
                }
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
        if ($user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }
        $request->setUserResolver(fn () => $user);

        return $this->profile($request);
    }
}
