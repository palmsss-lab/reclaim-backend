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
        return (new MailMessage)
            ->subject('Verify your Reclaim account email')
            ->line('Confirm this email address to use Reclaim self-service reports and claims.')
            ->action('Verify email address', $this->verificationUrl)
            ->line('This link expires in 60 minutes.');
    }
}