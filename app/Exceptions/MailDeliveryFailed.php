<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class MailDeliveryFailed extends RuntimeException implements ShouldntReport
{
    public const MESSAGE = "We couldn't send the account email. Try again shortly. If you just registered, sign in to request a new verification email.";

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE], 503)
            ->header('Cache-Control', 'no-store');
    }
}
