<?php

namespace App\Services;

use Laravel\Socialite\Two\GoogleProvider;

class PkceGoogleProvider extends GoogleProvider
{
    private string $verifier;

    public function withVerifier(string $verifier): static
    {
        $this->verifier = $verifier;

        return $this;
    }

    protected function getTokenFields($code)
    {
        return parent::getTokenFields($code) + ['code_verifier' => $this->verifier];
    }
}
