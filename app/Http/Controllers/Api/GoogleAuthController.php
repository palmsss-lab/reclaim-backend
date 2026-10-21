<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\BrowserSession;
use App\Actions\Accounts\GoogleAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ExchangeGoogleRequest;
use App\Http\Requests\Auth\StartGoogleRequest;
use App\Services\GoogleGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class GoogleAuthController extends Controller
{
    public function start(StartGoogleRequest $request, GoogleGateway $google)
    {
        return $this->begin($request, $google, false);
    }

    public function startLink(StartGoogleRequest $request, GoogleGateway $google)
    {
        return $this->begin($request, $google, true);
    }

    private function begin(Request $request, GoogleGateway $google, bool $link)
    {
        $data = $request->validated();
        $origin = config('services.google.frontend_origin');
        $redirect = config('services.google.redirect');
        abort_unless(config('services.google.client_id') && config('services.google.client_secret') &&
            $this->safeUrl($origin) && $origin === $this->origin($origin) && $this->safeUrl($redirect) &&
            parse_url($redirect, PHP_URL_PATH) === '/api/auth/google/callback' && ! parse_url($redirect, PHP_URL_QUERY),
            503, 'Google sign-in is not configured. Use your existing sign-in method.');
        $token = $link ? $request->user()->currentAccessToken() : null;
        if ($link) {
            abort_unless($token instanceof PersonalAccessToken || ($request->hasSession() && $request->attributes->get('sanctum')), 403);
            abort_unless($request->user()->has_local_password &&
                Hash::check($data['current_password'] ?? '', $request->user()->password), 422,
                'Confirm your current Reclaim password to link Google.');
        }
        $state = $this->random();
        $providerVerifier = $this->random();
        $url = $google->authorizationUrl($state, $providerVerifier);
        DB::table('google_auth_attempts')->insert(['state_hash' => hash('sha256', $state),
            'challenge' => $data['challenge'], 'provider_verifier' => Crypt::encryptString(json_encode([
                'verifier' => $providerVerifier,
                'session_hash' => $link && ! ($token instanceof PersonalAccessToken) ? hash('sha256', $request->session()->getId()) : null,
            ])),
            'user_id' => $link ? $request->user()->id : null, 'token_id' => $token instanceof PersonalAccessToken ? $token->id : null,
            'expires_at' => now()->addMinutes(5)]);

        return response()->json(['url' => $url, 'callback_origin' => $this->origin($redirect)])
            ->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request, GoogleGateway $google)
    {
        $message = ['error' => 'Google sign-in could not be completed. Please start again.'];
        $state = $request->query('state');
        if (! is_string($state) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $state)) {
            return $this->popup($message);
        }
        $attempt = DB::transaction(function () use ($state) {
            $row = DB::table('google_auth_attempts')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            if (! $row || $row->callback_used_at || now()->gte($row->expires_at)) {
                return null;
            }
            DB::table('google_auth_attempts')->where('state_hash', $row->state_hash)->update(['callback_used_at' => now()]);

            return $row;
        });
        if (! $attempt) {
            return $this->popup($message);
        }
        if ($request->query('error') === 'access_denied') {
            return $this->popup(['error' => 'Google sign-in was cancelled.']);
        }
        if ($request->query('error') || ! is_string($request->query('code')) || ! $request->query('code')) {
            return $this->popup($message);
        }
        try {
            $binding = json_decode(Crypt::decryptString($attempt->provider_verifier), true);
            $profile = $google->profile($binding['verifier']);
            $profile['_session_hash'] = $binding['session_hash'];
            $profile['email'] = is_string($profile['email'] ?? null) ? mb_strtolower(trim($profile['email'])) : null;
            $profile['name'] = is_string($profile['name'] ?? null) ? trim($profile['name']) : null;
            Validator::make($profile, ['subject' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
                'email' => ['required', 'email', 'max:200'], 'name' => ['required', 'string', 'max:120'],
                'verified' => ['required', 'boolean']])->validate();
            $code = $this->random();
            DB::table('google_auth_attempts')->where('state_hash', $attempt->state_hash)->update([
                'exchange_hash' => hash('sha256', $code), 'profile' => Crypt::encryptString(json_encode($profile)),
                'provider_verifier' => '', 'expires_at' => now()->addSeconds(60),
            ]);

            return $this->popup(['code' => $code]);
        } catch (\Throwable) {
            // Provider errors may contain credentials/codes. Do not log raw exceptions or responses.
            return $this->popup($message);
        }
    }

    public function exchange(ExchangeGoogleRequest $request, GoogleAuthentication $accounts)
    {
        return $this->complete($request, $accounts, false);
    }

    public function exchangeLink(ExchangeGoogleRequest $request, GoogleAuthentication $accounts)
    {
        return $this->complete($request, $accounts, true);
    }

    private function complete(Request $request, GoogleAuthentication $accounts, bool $link)
    {
        $data = $request->validated();
        // Consume before account work: provider codes and local exchange codes are both single-use.
        $profile = DB::transaction(function () use ($request, $data, $link) {
            $row = DB::table('google_auth_attempts')->where('exchange_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            abort_unless($row && $row->profile && now()->lt($row->expires_at), 422, 'The Google attempt expired or was already used.');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['verifier'], true)), '+/', '-_'), '=');
            abort_unless(hash_equals($row->challenge, $challenge), 422, 'Invalid sign-in attempt.');
            $token = $link ? $request->user()->currentAccessToken() : null;
            abort_unless($link ? ($row->user_id === $request->user()->id && $row->token_id === ($token instanceof PersonalAccessToken ? $token->id : null)) : $row->user_id === null, 403);
            $profile = json_decode(Crypt::decryptString($row->profile), true);
            if ($link && ! ($token instanceof PersonalAccessToken)) {
                abort_unless($request->hasSession() && is_string($profile['_session_hash'] ?? null)
                    && hash_equals($profile['_session_hash'], hash('sha256', $request->session()->getId())), 403);
            }
            DB::table('google_auth_attempts')->where('state_hash', $row->state_hash)->delete();

            return $profile;
        });
        try {
            $result = DB::transaction(function () use ($accounts, $profile, $link, $request) {
                $user = $accounts->resolve($profile, $link ? $request->user() : null);
                if ($link) {
                    return ['message' => 'Google sign-in is linked.'];
                }

                return app(BrowserSession::class)->authenticate($request, $user, 'reclaim-google');
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Sign-in or linking could not be completed. Sign in with your existing method and check Account Settings.');
        }

        // A linked existing staff account keeps its server-owned privileges. Registration never creates staff.
        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function safeUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']) &&
            ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost'], true)));
    }

    private function popup(array $message)
    {
        $nonce = base64_encode(random_bytes(18));
        $origin = json_encode(config('services.google.frontend_origin'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $payload = json_encode(['type' => 'reclaim:google'] + $message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Reclaim Google sign-in</title><p>Return to the Reclaim window to finish signing in. You may close this window.</p><script nonce="'.$nonce.'">history.replaceState(null,"",location.pathname);if(window.opener){window.opener.postMessage('.$payload.','.$origin.');window.close();}</script></html>')
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'")
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
