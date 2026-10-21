<?php

namespace App\Mail;

use App\Exceptions\MailDeliveryFailed;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Throwable;

class BrevoTransport extends AbstractTransport
{
    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
        parent::__construct();

        if (trim($key) === '' || ! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            throw new MailDeliveryFailed;
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new MailDeliveryFailed;
        }

        $sender = $email->getFrom()[0];
        try {
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['api-key' => $this->key])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => ['email' => $sender->getAddress(), 'name' => $sender->getName()],
                    'to' => array_map(fn ($address) => ['email' => $address->getAddress()], $message->getEnvelope()->getRecipients()),
                    'subject' => $email->getSubject(),
                    ...($email->getHtmlBody() !== null ? ['htmlContent' => $email->getHtmlBody()] : []),
                    ...($email->getTextBody() !== null ? ['textContent' => $email->getTextBody()] : []),
                ]);
            $messageId = $response->json('messageId');
            if ($response->status() !== 201 || ! is_string($messageId) || trim($messageId) === '') {
                throw new MailDeliveryFailed;
            }
        } catch (Throwable) {
            // Provider exceptions can contain credentials or email bodies; never chain or log them.
            throw new MailDeliveryFailed;
        }

        $message->setMessageId($messageId);
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
