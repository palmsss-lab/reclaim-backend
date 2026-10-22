<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\BrowserSession;
use App\Actions\Accounts\RecoverPassword;
use App\Actions\Accounts\VerifyAccountEmail;
use App\Exceptions\MailDeliveryFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Requests\Auth\ResetUserPasswordRequest;
use App\Http\Requests\Auth\UpdateUserProfileRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
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
        try {
            $user->sendEmailVerificationNotification();
        } catch (MailDeliveryFailed) {
            return response()->json([
                'code' => 'account_created_email_failed', 'account_created' => true,
                'verification_email' => ['status' => 'failed'], 'message' => MailDeliveryFailed::MESSAGE,
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => [
            'id' => (int) $user->id, 'email' => $user->email,
            'full_name' => $user->name, 'email_verified' => false,
        ], 'verification_email' => ['status' => 'accepted'],
            'message' => 'Account created. The verification email was accepted for sending. Check your inbox and spam folder. If it does not arrive, sign in to request a new verification email.',
        ], 201)->header('Cache-Control', 'private, no-store');
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

    public function verifyEmailCode(VerifyEmailCodeRequest $request, VerifyAccountEmail $verification): JsonResponse
    {
        $verification->verify($request->user(), $request->validated('code'));

        return response()->json(['data' => ['email_verified' => true], 'message' => 'Email verified. You can now use Reclaim reports and claims.'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->person_id && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If verification is required, your code email was accepted for sending. Check your inbox and spam folder. Codes expire after 10 minutes.'])->header('Cache-Control', 'private, no-store');
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
        if ($user->wasChanged('email')) {
            $user->sendEmailVerificationNotification();
        }
        $request->setUserResolver(fn () => $user);

        return $this->profile($request);
    }
}
