<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecoverPassword
{
    public const AUTHORIZATION_SECONDS = 300;

    private const AUTHORIZATION_PREFIX = 'reset:';

    public const INVALID_AUTHORIZATION = 'Your password-reset session is invalid or has expired. Request another code.';

    public const INVALID_CODE = 'This reset code is incorrect, expired, or no longer valid. Request another code.';

    public function send(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $account = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($account->email !== $user->email || ! $account->has_local_password || ! $account->isActive()) {
                return;
            }
            $sendKey = 'password-code-send:'.hash('sha256', $account->email);
            if (RateLimiter::tooManyAttempts($sendKey, 1)) {
                return;
            }
            $previous = DB::table('password_reset_tokens')->where('email', $account->email)->first();
            $hasCode = $previous && ! str_starts_with($previous->token, self::AUTHORIZATION_PREFIX);
            if ($hasCode && Carbon::parse($previous->created_at)->gt(now()->subMinute())) {
                return;
            }

            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while ($hasCode && Hash::check($this->digest($code), $previous->token));

            DB::table('password_reset_tokens')->updateOrInsert(['email' => $account->email], [
                'token' => Hash::make($this->digest($code)), 'created_at' => now(),
            ]);
            // Commit the replacement only after delivery is confirmed; failed sends preserve the previous code.
            $account->sendPasswordResetNotification($code);
            RateLimiter::hit($sendKey, 60);
        });
    }

    public function verify(array $data): string
    {
        $authorization = DB::transaction(function () use ($data): ?string {
            $account = User::query()->where('email', $data['email'])->lockForUpdate()->first();
            if (! $account || ! $account->has_local_password || ! $account->isActive()) {
                return null;
            }
            $token = DB::table('password_reset_tokens')->where('email', $account->email)->first();
            if (! $token || str_starts_with($token->token, self::AUTHORIZATION_PREFIX)
                || Carbon::parse($token->created_at)->addMinutes(10)->lte(now())) {
                return null;
            }
            $attemptKey = 'password-code:'.hash('sha256', $account->email.'|'.$token->token);
            if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
                return null;
            }
            if (! Hash::check($this->digest($data['code']), $token->token)) {
                if (RateLimiter::hit($attemptKey, 600) >= 5) {
                    DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                }

                return null;
            }

            $authorization = bin2hex(random_bytes(32));
            DB::table('password_reset_tokens')->where('email', $account->email)->update([
                'token' => self::AUTHORIZATION_PREFIX.hash('sha256', $account->id.'|'.$authorization),
                'created_at' => now(),
            ]);
            RateLimiter::clear($attemptKey);

            return $authorization;
        });

        // Incorrect-attempt updates must commit before the validation exception is thrown.
        if (! $authorization) {
            throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
        }

        return $authorization;
    }

    public function reset(array $data): void
    {
        $user = DB::transaction(function () use ($data): ?User {
            $account = User::query()->where('email', $data['email'])->lockForUpdate()->first();
            if (! $account || ! $account->has_local_password || ! $account->isActive()) {
                return null;
            }
            $token = DB::table('password_reset_tokens')->where('email', $account->email)->first();
            if (! $token || ! str_starts_with($token->token, self::AUTHORIZATION_PREFIX)
                || Carbon::parse($token->created_at)->addSeconds(self::AUTHORIZATION_SECONDS)->lte(now())
                || ! hash_equals($token->token, self::AUTHORIZATION_PREFIX.hash('sha256', $account->id.'|'.$data['authorization']))) {
                return null;
            }

            $account->password = $data['password'];
            $account->remember_token = Str::random(60);
            $account->save();
            $account->tokens()->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();

            return $account;
        });

        if (! $user) {
            throw ValidationException::withMessages(['authorization' => self::INVALID_AUTHORIZATION]);
        }
        event(new PasswordReset($user));
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
