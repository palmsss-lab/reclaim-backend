<?php

namespace App\Services;

use GuzzleHttp\Client;
use Laravel\Socialite\Facades\Socialite;

class GoogleGateway
{
    private function driver(string $verifier): PkceGoogleProvider
    {
        return Socialite::buildProvider(PkceGoogleProvider::class, config('services.google'))
            ->stateless()->withVerifier($verifier)->setHttpClient(new Client(['timeout' => 15, 'connect_timeout' => 5]));
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $this->driver($verifier)->with([
            'state' => $state, 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ])->redirect()->getTargetUrl();
    }

    public function profile(string $verifier): array
    {
        // Socialite exchanges the callback code server-side and fetches Google's userinfo over TLS.
        // Provider tokens are deliberately never persisted or returned to the browser.
        $user = $this->driver($verifier)->user();

        return ['subject' => $user->getId(), 'email' => $user->getEmail(),
            'name' => $user->getName(), 'verified' => ($user->user['email_verified'] ?? false) === true,
            'hosted_domain' => $user->user['hd'] ?? null];
    }
}
