<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrowserSession
{
    public function authenticate(Request $request, User $user, string $tokenName): array
    {
        if ($request->attributes->get('sanctum') && $request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));

            return ['session_authenticated' => true];
        }

        return ['token_type' => 'Bearer', 'token' => $user->createToken($tokenName, [$user->staff_id ? 'staff' : 'user'])->plainTextToken];
    }

    public function end(Request $request): void
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
