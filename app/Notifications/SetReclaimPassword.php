<?php

namespace App\Notifications;

use App\Mail\AccountEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetReclaimPassword extends Notification
{
    public function __construct(public readonly string $proof) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        AccountEmail::prepare();

        return (new MailMessage)->subject('Set your separate Reclaim password')
            ->text('mail.account-link-text', [
                'title' => 'Set your separate Reclaim password',
                'instructions' => 'You requested a separate Reclaim password. Your Google password is never shared with Reclaim.',
                'actionLabel' => 'Set Reclaim password',
                'actionUrl' => rtrim(config('services.google.frontend_origin'), '/').'/set-password?'.http_build_query(['token' => $this->proof], '', '&', PHP_QUERY_RFC3986),
                'expiry' => 'This link expires in 15 minutes and can be used once. Sign in to the same Reclaim account to complete setup. Ignore this email if you did not request it.',
            ]);
    }
}
