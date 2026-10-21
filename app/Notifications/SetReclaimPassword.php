<?php

namespace App\Notifications;

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
        return (new MailMessage)->subject('Set your separate Reclaim password')
            ->line('You requested a separate Reclaim password. Your Google password is never shared with Reclaim.')
            ->action('Set Reclaim password', config('services.google.frontend_origin').'/set-password?token='.urlencode($this->proof))
            ->line('This link expires in 15 minutes and can be used once. Sign in to the same Reclaim account to complete setup. Ignore this email if you did not request it.');
    }
}
