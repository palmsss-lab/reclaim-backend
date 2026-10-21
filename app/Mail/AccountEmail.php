<?php

namespace App\Mail;

use App\Exceptions\MailDeliveryFailed;
use Illuminate\Support\Facades\URL;

class AccountEmail
{
    public static function prepare(): void
    {
        if (! app()->isProduction()) {
            return;
        }
        if (config('mail.default') !== 'brevo') {
            throw new MailDeliveryFailed;
        }
        foreach ([config('app.url'), config('services.google.frontend_origin')] as $origin) {
            $parts = is_string($origin) ? parse_url($origin) : false;
            if (! $parts || ! filter_var($origin, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new MailDeliveryFailed;
            }
        }
        // Email signatures must use the configured canonical origin, never an incoming Host header.
        URL::forceRootUrl(rtrim(config('app.url'), '/'));
        URL::forceScheme('https');
    }
}
