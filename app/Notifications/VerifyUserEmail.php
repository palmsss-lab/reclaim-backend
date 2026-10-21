<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyUserEmail extends Notification
{
    use Queueable;

    public function __construct(private readonly string $verificationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verify your Reclaim account email')
            ->text('mail.account-link-text', [
                'title' => 'Verify your Reclaim account email',
                'instructions' => 'Confirm this email address to use Reclaim self-service reports and claims.',
                'actionLabel' => 'Verify email address',
                'actionUrl' => $this->verificationUrl,
                'expiry' => 'This link expires in 60 minutes. Ignore this email if you did not request it.',
            ]);
    }
}
