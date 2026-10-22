<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyUserEmail extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verify your Reclaim account email')
            ->text('mail.verification-code-text', ['code' => $this->code]);
    }
}
