<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetUserPassword extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Reclaim password')
            ->line('A password reset was requested for your Reclaim account.')
            ->line('Your password reset code is:')
            ->line($this->code)
            ->line('This code expires in 10 minutes and can be used once. Enter it in Reclaim to choose a new password.')
            ->line('If you did not request this change, ignore this email.');
    }
}
