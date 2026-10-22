<?php

namespace App\Actions\Accounts;

use App\Mail\AccountEmail;
use App\Models\User;
use App\Notifications\VerifyUserEmail;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyAccountEmail
{
    public const INVALID_CODE = 'This verification code is incorrect, expired, or no longer valid. Request another code.';

    public function send(User $user): void
    {
        AccountEmail::prepare();
        DB::transaction(function () use ($user): void {
            $account = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->isActive() && $account->person_id && $account->email === $user->email, 409,
                'Refresh your account before requesting another verification code.');
            if ($account->hasVerifiedEmail()) {
                return;
            }
            $previous = DB::table('email_verification_codes')->where('user_id', $account->id)->first();
            $retryAfter = $previous ? (int) ceil(now()->diffInSeconds(Carbon::parse($previous->created_at)->addMinute(), false)) : 0;
            if ($retryAfter > 0) {
                throw new HttpResponseException(response()->json(['message' => 'Wait before requesting another verification code.'], 429)
                    ->header('Retry-After', $retryAfter)->header('Cache-Control', 'private, no-store'));
            }
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while ($previous && Hash::check($this->digest($code), $previous->code_hash));
            DB::table('email_verification_codes')->updateOrInsert(['user_id' => $account->id], [
                'email' => $account->email, 'code_hash' => Hash::make($this->digest($code)), 'attempts' => 0,
                'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            // Preserve the previous code if the provider rejects the replacement email.
            $account->notify(new VerifyUserEmail($code));
        });
    }

    public function verify(User $user, string $code): void
    {
        $verified = DB::transaction(function () use ($user, $code): bool {
            $account = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $stored = DB::table('email_verification_codes')->where('user_id', $account->id)->first();
            if (! $account->isActive() || ! $account->person_id || $account->hasVerifiedEmail() || ! $stored
                || $stored->email !== $account->email || $stored->attempts >= 5 || Carbon::parse($stored->expires_at)->lte(now())) {
                return false;
            }
            if (! Hash::check($this->digest($code), $stored->code_hash)) {
                DB::table('email_verification_codes')->where('user_id', $account->id)->increment('attempts');

                return false;
            }
            $account->markEmailAsVerified();
            DB::table('email_verification_codes')->where('user_id', $account->id)->delete();

            return true;
        });
        // Commit incorrect-attempt counts before throwing the validation error.
        if (! $verified) {
            throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
        }
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
